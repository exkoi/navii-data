<?php

declare(strict_types=1);

require __DIR__ . '/../vendor/autoload.php';

date_default_timezone_set('Asia/Tokyo');

use Exp\NaviiData\Db;

$config = require __DIR__ . '/../config/config.php';

$args = array_slice($argv, 1);
if (!in_array('--yes', $args, true)) {
    fwrite(STDERR, "danger: this wipes facilities/detail_state/detail_snapshots/list_progress/fetch_log.\n");
    fwrite(STDERR, "rerun with --yes to confirm. usage: php bin/reset-state.php --yes [--keep-snapshots]\n");
    exit(1);
}

$keepHtml = in_array('--keep-html', $args, true);

$pdo = Db::open($config['db_path']);
$pdo->beginTransaction();
try {
    if ($keepHtml) {
        // 施設レコードと HTML は残し、状態だけリセット
        $pdo->exec('UPDATE facilities
                    SET status = "pending", retry_count = 0,
                        last_error = NULL, next_attempt_at = NULL');
    } else {
        $pdo->exec('DELETE FROM facilities');
    }
    $pdo->exec('DELETE FROM list_progress');
    $pdo->exec('DELETE FROM fetch_log');
    $pdo->commit();
} catch (Throwable $e) {
    $pdo->rollBack();
    throw $e;
}

@unlink($config['stop_file']);
fwrite(STDOUT, "reset complete" . ($keepHtml ? ' (facilities + html kept, status reset)' : '') . "\n");
