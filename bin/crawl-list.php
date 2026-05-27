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

$pdo = Db::open($config['db_path']);
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
    $logger->info('all target prefs completed for known pages');
    exit(0);
}

[$prefCd, $page] = $next;
$logger->info("fetch list pref={$prefCd} page={$page}");

try {
    $result = $http->get($config['list_path'], [
        'sjk'  => $config['list_sjk'],
        'jc'   => $config['list_jc'],
        'pref' => $prefCd,
        'page' => $page,
    ]);
} catch (Throwable $e) {
    upsertListProgress($pdo, $prefCd, $page, null, null, null, 'error');
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
    // 新規施設は status='pending' で挿入、既存施設は last_seen_at を更新するだけ。
    $upsertFacility = $pdo->prepare(
        'INSERT INTO facilities (kikan_cd, pref_cd, kikan_kbn, first_seen_at, last_seen_at, status)
         VALUES (:cd, :pref, :kbn, datetime("now", "+9 hours"), datetime("now", "+9 hours"), "pending")
         ON CONFLICT(kikan_cd, pref_cd, kikan_kbn) DO UPDATE SET last_seen_at = datetime("now", "+9 hours")'
    );

    foreach ($parsed['facilities'] as $f) {
        $upsertFacility->execute([':cd' => $f['kikan_cd'], ':pref' => $f['pref_cd'], ':kbn' => $f['kikan_kbn']]);
    }

    upsertListProgress(
        $pdo,
        $prefCd,
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

$logger->info("done pref={$prefCd} page={$page} stored=" . count($parsed['facilities']));

/**
 * @param list<string> $targetPrefs
 * @return array{0:string,1:int}|null
 */
function pickNextListJob(PDO $pdo, array $targetPrefs): ?array
{
    // 各 pref で page=0 がまだ done でないなら最優先（total_count を把握するため）
    foreach ($targetPrefs as $pref) {
        $row = $pdo->prepare('SELECT status FROM list_progress WHERE pref_cd = :p AND page = 0');
        $row->execute([':p' => $pref]);
        $r = $row->fetch();
        if (!$r || $r['status'] !== 'done') {
            return [$pref, 0];
        }
    }

    // 各 pref について、次の未取得ページを選ぶ
    foreach ($targetPrefs as $pref) {
        $stmt = $pdo->prepare(
            'SELECT total_count, MAX(page) AS last_page
             FROM list_progress
             WHERE pref_cd = :p AND status = "done"'
        );
        $stmt->execute([':p' => $pref]);
        $row = $stmt->fetch();
        if (!$row || $row['last_page'] === null) {
            return [$pref, 0];
        }
        $total = (int)($row['total_count'] ?? 0);
        $lastPage = (int)$row['last_page'];
        $perPage = 20;
        $maxPage = $total > 0 ? (int)floor(($total - 1) / $perPage) : 0;
        if ($lastPage < $maxPage) {
            return [$pref, $lastPage + 1];
        }
    }

    return null;
}

function upsertListProgress(
    PDO $pdo,
    string $prefCd,
    int $page,
    ?int $httpStatus,
    ?int $totalCount,
    ?int $facilityCount,
    string $status,
): void {
    $stmt = $pdo->prepare(
        'INSERT INTO list_progress (pref_cd, page, http_status, total_count, facility_count, fetched_at, status)
         VALUES (:p, :pg, :st, :tc, :fc, datetime("now", "+9 hours"), :s)
         ON CONFLICT(pref_cd, page) DO UPDATE SET
           http_status = excluded.http_status,
           total_count = COALESCE(excluded.total_count, list_progress.total_count),
           facility_count = excluded.facility_count,
           fetched_at = excluded.fetched_at,
           status = excluded.status'
    );
    $stmt->execute([
        ':p'  => $prefCd,
        ':pg' => $page,
        ':st' => $httpStatus,
        ':tc' => $totalCount,
        ':fc' => $facilityCount,
        ':s'  => $status,
    ]);
}
