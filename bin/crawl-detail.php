<?php

declare(strict_types=1);

require __DIR__ . '/../vendor/autoload.php';

date_default_timezone_set('Asia/Tokyo');

use Exp\NaviiData\CircuitBreaker;
use Exp\NaviiData\Db;
use Exp\NaviiData\HtmlNormalizer;
use Exp\NaviiData\HttpClient;
use Exp\NaviiData\Logger;
use Exp\NaviiData\RateLimiter;

$config = require __DIR__ . '/../config/config.php';

$lockHandle = fopen($config['lock_file'], 'c');
if ($lockHandle === false || !flock($lockHandle, LOCK_EX | LOCK_NB)) {
    fwrite(STDOUT, "another instance is running, exit\n");
    exit(0);
}

$logger = new Logger($config['log_dir'], 'detail');

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

$targetKbns = $config['target_kbns'];
$maxPerRun = (int)$config['max_per_run'];

$kbnPlaceholders = $targetKbns
    ? implode(',', array_map(fn($_, $i) => ':k' . $i, $targetKbns, array_keys($targetKbns)))
    : '';

$sql = 'SELECT kikan_cd, pref_cd, kikan_kbn, content_hash, retry_count
        FROM facilities
        WHERE (status = "pending"
               OR (status = "error" AND retry_count < :max_retry AND (next_attempt_at IS NULL OR next_attempt_at <= datetime("now", "+9 hours"))))';
if ($kbnPlaceholders !== '') {
    $sql .= " AND kikan_kbn IN ({$kbnPlaceholders})";
}
$sql .= ' ORDER BY status ASC, retry_count ASC, kikan_cd ASC LIMIT :lim';

$stmt = $pdo->prepare($sql);
$stmt->bindValue(':max_retry', (int)$config['max_retry'], PDO::PARAM_INT);
$stmt->bindValue(':lim', $maxPerRun, PDO::PARAM_INT);
foreach ($targetKbns as $i => $k) {
    $stmt->bindValue(':k' . $i, (string)$k, PDO::PARAM_STR);
}
$stmt->execute();
$jobs = $stmt->fetchAll();

if (!$jobs) {
    $logger->info('no pending detail jobs');
    exit(0);
}

$logger->info('processing ' . count($jobs) . ' detail jobs');

$processed = 0;
foreach ($jobs as $idx => $job) {
    if (file_exists($config['stop_file'])) {
        $logger->warn('stop file appeared mid-run, aborting');
        break;
    }

    $kikanCd = $job['kikan_cd'];
    $prefCd  = $job['pref_cd'];
    $kikanKbn = $job['kikan_kbn'];
    $previousHash = $job['content_hash'];

    try {
        $result = $http->get($config['detail_path'], [
            'prefCd'   => $prefCd,
            'kikanCd'  => $kikanCd,
            'kikanKbn' => $kikanKbn,
        ]);
    } catch (Throwable $e) {
        markError($pdo, $kikanCd, $prefCd, $kikanKbn, $e->getMessage(), (int)$job['retry_count'] + 1);
        $logger->error("detail fetch failed cd={$kikanCd}: " . $e->getMessage());
        // 429/403 で .stop が作られていれば次ループで break
        if (file_exists($config['stop_file'])) {
            break;
        }
        if ($idx < count($jobs) - 1) {
            $rate->sleep();
        }
        continue;
    }

    $hash = hash('sha256', HtmlNormalizer::canonicalize($result['body']));
    $changed = ($previousHash !== $hash);

    $pdo->beginTransaction();
    try {
        // 1施設1レコード方針：HTMLは常に最新を上書き保存（履歴は持たない）。
        // last_changed_at は hash が前回と異なった時のみ更新する。
        $sql = 'UPDATE facilities
                SET html            = :html,
                    content_hash    = :hash,
                    bytes           = :bytes,
                    http_status     = :status,
                    last_scraped_at = datetime("now", "+9 hours"),
                    last_seen_at    = datetime("now", "+9 hours"),'
            . ($changed ? ' last_changed_at = datetime("now", "+9 hours"),' : '')
            . ' status          = "done",
                    retry_count     = 0,
                    last_error      = NULL,
                    next_attempt_at = NULL
                WHERE kikan_cd = :cd AND pref_cd = :pref AND kikan_kbn = :kbn';

        $upd = $pdo->prepare($sql);
        $upd->bindValue(':html', $result['body'], PDO::PARAM_LOB);
        $upd->bindValue(':hash', $hash);
        $upd->bindValue(':bytes', $result['bytes'], PDO::PARAM_INT);
        $upd->bindValue(':status', $result['status'], PDO::PARAM_INT);
        $upd->bindValue(':cd', $kikanCd);
        $upd->bindValue(':pref', $prefCd);
        $upd->bindValue(':kbn', $kikanKbn);
        $upd->execute();

        $pdo->commit();
    } catch (Throwable $e) {
        $pdo->rollBack();
        markError($pdo, $kikanCd, $prefCd, $kikanKbn, 'db write: ' . $e->getMessage(), (int)$job['retry_count'] + 1);
        $logger->error("detail save failed cd={$kikanCd}: " . $e->getMessage());
    }

    $processed++;
    $logger->info(sprintf(
        'detail cd=%s kbn=%s %s bytes=%d',
        $kikanCd,
        $kikanKbn,
        $changed ? 'NEW/CHANGED' : 'unchanged',
        $result['bytes'],
    ));

    if ($idx < count($jobs) - 1) {
        $rate->sleep();
    }
}

$logger->info("done processed={$processed}");

function markError(PDO $pdo, string $cd, string $pref, string $kbn, string $msg, int $retryCount): void
{
    // バックオフ：retry_count に応じて 1分→3分→9分→27分
    $backoffMin = (int)pow(3, $retryCount - 1);
    $nextAt = (new DateTime('now', new DateTimeZone('Asia/Tokyo')))
        ->modify("+{$backoffMin} minutes")
        ->format('Y-m-d H:i:s');

    $stmt = $pdo->prepare(
        'UPDATE facilities
         SET status = "error",
             retry_count = :rc,
             last_error = :err,
             next_attempt_at = :next
         WHERE kikan_cd = :cd AND pref_cd = :pref AND kikan_kbn = :kbn'
    );
    $stmt->execute([
        ':rc'   => $retryCount,
        ':err'  => substr($msg, 0, 500),
        ':next' => $nextAt,
        ':cd'   => $cd,
        ':pref' => $pref,
        ':kbn'  => $kbn,
    ]);
}
