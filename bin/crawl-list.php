<?php

declare(strict_types=1);

require __DIR__ . '/../vendor/autoload.php';

date_default_timezone_set('Asia/Tokyo');

use Exp\NaviiData\CircuitBreaker;
use Exp\NaviiData\Db;
use Exp\NaviiData\HttpClient;
use Exp\NaviiData\ListPageParser;
use Exp\NaviiData\Logger;
use Exp\NaviiData\RateLimiter;

$config = require __DIR__ . '/../config/config.php';

$lockHandle = fopen($config['lock_file'], 'c');
if ($lockHandle === false || !flock($lockHandle, LOCK_EX | LOCK_NB)) {
    fwrite(STDOUT, "another instance is running, exit\n");
    exit(0);
}

$logger = new Logger($config['log_dir'], 'list');

if (file_exists($config['stop_file'])) {
    $logger->warn("stop file present at {$config['stop_file']}, abort");
    exit(0);
}

$pdo = Db::open($config['db_path'], stateDbPath: $config['state_db_path']);
$logger->attachPdo($pdo);

$breaker = new CircuitBreaker($config['stop_file'], $config['circuit_breaker_threshold'], $logger);
$http = new HttpClient(
    $config['user_agent'],
    $config['base_url'],
    $config['connect_timeout'],
    $config['total_timeout'],
    $config['max_retry'],
    $logger,
    $breaker,
);
$rate = new RateLimiter($config['sleep_min_sec'], $config['sleep_max_sec']);

$targetPrefs = $config['target_prefs'];
if (!$targetPrefs) {
    $logger->error('NAVII_TARGET_PREFS is empty, nothing to do');
    exit(1);
}

$next = pickNextListJob($pdo, $targetPrefs);
if ($next === null) {
    $logger->info('all target municipalities completed for known pages');
    exit(0);
}

[$lo, $page, $loName] = $next;
$logger->info("fetch list lo={$lo} ({$loName}) page={$page}");

try {
    $result = $http->get($config['list_path'], [
        'sortNo' => $config['list_sort_no'],
        'sjk'    => $config['list_sjk'],
        'jc'     => $config['list_jc'],
        'lo'     => $lo,
        'page'   => $page,
    ]);
} catch (Throwable $e) {
    upsertListProgress($pdo, $lo, $page, null, null, null, 'error');
    $logger->error('fetch failed: ' . $e->getMessage());
    exit(1);
}

$parsed = ListPageParser::parse($result['body']);
$logger->info(sprintf(
    'parsed total=%s max_page=%s current=%s facilities=%d',
    $parsed['total_count'] ?? 'null',
    $parsed['max_page'] ?? 'null',
    $parsed['current_page'] ?? 'null',
    count($parsed['facilities']),
));

$pdo->beginTransaction();
try {
    // 新規施設は status='pending' で挿入、既存施設は last_seen_at と code5 を更新。
    // code5 はクロール起点 lo の後5桁。同一施設が別 lo で再ヒットしたら最後の値で上書き。
    $code5 = substr($lo, 1);
    $upsertFacility = $pdo->prepare(
        'INSERT INTO facilities (kikan_cd, pref_cd, kikan_kbn, code5, first_seen_at, last_seen_at, status)
         VALUES (:cd, :pref, :kbn, :code5, datetime("now", "+9 hours"), datetime("now", "+9 hours"), "pending")
         ON CONFLICT(kikan_cd, pref_cd, kikan_kbn) DO UPDATE SET
           last_seen_at = datetime("now", "+9 hours"),
           code5        = excluded.code5'
    );

    foreach ($parsed['facilities'] as $f) {
        $upsertFacility->execute([
            ':cd'    => $f['kikan_cd'],
            ':pref'  => $f['pref_cd'],
            ':kbn'   => $f['kikan_kbn'],
            ':code5' => $code5,
        ]);
    }

    upsertListProgress(
        $pdo,
        $lo,
        $page,
        $result['status'],
        $parsed['total_count'],
        count($parsed['facilities']),
        'done',
    );

    $pdo->commit();
} catch (Throwable $e) {
    $pdo->rollBack();
    $logger->error('db write failed: ' . $e->getMessage());
    exit(1);
}

$logger->info("done lo={$lo} ({$loName}) page={$page} stored=" . count($parsed['facilities']));

/**
 * 次にクロールすべき (lo, page, name) を選ぶ。target_prefs に含まれる都道府県の
 * 市区町村のうち、DesignatedCity（政令市の親、ナビイでは無効）を除外。
 *
 * 優先順:
 *  1. まだ page=0 を取得していない lo（total_count を把握するため）
 *  2. page=0 の total_count から計算した max_page まで未到達の lo
 *
 * @param list<string> $targetPrefs
 * @return array{0:string,1:int,2:string}|null
 */
function pickNextListJob(PDO $pdo, array $targetPrefs): ?array
{
    $placeholders = implode(',', array_fill(0, count($targetPrefs), '?'));
    $stmt = $pdo->prepare(
        "SELECT lo, name FROM state.municipalities
         WHERE pref_cd IN ({$placeholders})
           AND admin_class != 'DesignatedCity'
         ORDER BY code5"
    );
    $stmt->execute(array_values($targetPrefs));
    $munis = $stmt->fetchAll();

    if (!$munis) {
        return null;
    }

    // 1. page=0 がまだ done でない lo を優先
    $checkP0 = $pdo->prepare('SELECT status FROM state.list_progress WHERE lo = :lo AND page = 0');
    foreach ($munis as $m) {
        $checkP0->execute([':lo' => $m['lo']]);
        $row = $checkP0->fetch();
        if (!$row || $row['status'] !== 'done') {
            return [(string)$m['lo'], 0, (string)$m['name']];
        }
    }

    // 2. 各 lo について、次の未取得ページを選ぶ
    $perPage = 20;
    $progress = $pdo->prepare(
        'SELECT total_count, MAX(page) AS last_page
         FROM state.list_progress
         WHERE lo = :lo AND status = "done"'
    );
    foreach ($munis as $m) {
        $progress->execute([':lo' => $m['lo']]);
        $row = $progress->fetch();
        if (!$row || $row['last_page'] === null) {
            return [(string)$m['lo'], 0, (string)$m['name']];
        }
        $total = (int)($row['total_count'] ?? 0);
        $lastPage = (int)$row['last_page'];
        $maxPage = $total > 0 ? (int)floor(($total - 1) / $perPage) : 0;
        if ($lastPage < $maxPage) {
            return [(string)$m['lo'], $lastPage + 1, (string)$m['name']];
        }
    }

    return null;
}

function upsertListProgress(
    PDO $pdo,
    string $lo,
    int $page,
    ?int $httpStatus,
    ?int $totalCount,
    ?int $facilityCount,
    string $status,
): void {
    $stmt = $pdo->prepare(
        'INSERT INTO state.list_progress (lo, page, http_status, total_count, facility_count, fetched_at, status)
         VALUES (:lo, :pg, :st, :tc, :fc, datetime("now", "+9 hours"), :s)
         ON CONFLICT(lo, page) DO UPDATE SET
           http_status = excluded.http_status,
           total_count = COALESCE(excluded.total_count, state.list_progress.total_count),
           facility_count = excluded.facility_count,
           fetched_at = excluded.fetched_at,
           status = excluded.status'
    );
    $stmt->execute([
        ':lo' => $lo,
        ':pg' => $page,
        ':st' => $httpStatus,
        ':tc' => $totalCount,
        ':fc' => $facilityCount,
        ':s'  => $status,
    ]);
}
