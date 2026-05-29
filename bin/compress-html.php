#!/usr/bin/env php
<?php

declare(strict_types=1);

/**
 * 既存 facilities.html を gzip圧縮BLOBに一括変換するワンショット移行スクリプト。
 *
 * - 非圧縮の行だけを圧縮する（既に圧縮済みの行はスキップ＝再実行しても安全）。
 * - メモリに全件を載せず rowid 単位で1件ずつ処理する（巨大DB対応）。
 * - 最後に VACUUM で実ファイルを縮小する。
 *
 * 使い方:
 *   php bin/compress-html.php                 # config の db_path を対象
 *   php bin/compress-html.php --db=/path.sqlite
 *   php bin/compress-html.php --dry-run       # 書き込まず削減量だけ試算
 *   php bin/compress-html.php --no-vacuum     # VACUUM を省略
 */

require __DIR__ . '/../vendor/autoload.php';

use Exp\NaviiData\HtmlCodec;

$config = require __DIR__ . '/../config/config.php';

$dbPath   = $config['db_path'];
$dryRun   = in_array('--dry-run', $argv, true);
$noVacuum = in_array('--no-vacuum', $argv, true);
foreach ($argv as $a) {
    if (str_starts_with($a, '--db=')) {
        $dbPath = substr($a, 5);
    }
}

if (!is_file($dbPath)) {
    fwrite(STDERR, "DB not found: {$dbPath}\n");
    exit(1);
}

clearstatcache(true, $dbPath);
$fileBefore = filesize($dbPath);
printf("DB: %s (%.1f MB)%s\n", $dbPath, $fileBefore / 1048576, $dryRun ? '  [DRY RUN]' : '');

$pdo = new PDO('sqlite:' . $dbPath);
$pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

$ids = $pdo->query('SELECT rowid FROM facilities WHERE html IS NOT NULL')
           ->fetchAll(PDO::FETCH_COLUMN);
$total = count($ids);
printf("rows with html: %d\n", $total);

$sel = $pdo->prepare('SELECT html FROM facilities WHERE rowid = ?');
$upd = $pdo->prepare('UPDATE facilities SET html = ? WHERE rowid = ?');

$compressed = 0;
$skipped    = 0;
$rawSum     = 0;
$newSum     = 0;
$i          = 0;

if (!$dryRun) {
    $pdo->beginTransaction();
}

foreach ($ids as $rowid) {
    $sel->execute([$rowid]);
    $html = $sel->fetchColumn();
    $i++;

    if ($html === false || $html === null || $html === '') {
        continue;
    }
    if (HtmlCodec::isCompressed($html)) {
        $skipped++;
        continue;
    }

    $enc = HtmlCodec::encode($html);
    $rawSum += strlen($html);
    $newSum += strlen($enc);

    if (!$dryRun) {
        $upd->bindValue(1, $enc, PDO::PARAM_LOB);
        $upd->bindValue(2, $rowid, PDO::PARAM_INT);
        $upd->execute();

        // バッチコミットで WAL の肥大を防ぐ。
        if ($compressed % 500 === 499) {
            $pdo->commit();
            $pdo->beginTransaction();
        }
    }
    $compressed++;

    if ($i % 500 === 0) {
        printf("  %d/%d  compressed=%d skipped=%d\n", $i, $total, $compressed, $skipped);
    }
}

if (!$dryRun && $pdo->inTransaction()) {
    $pdo->commit();
}

printf("\ncompressed=%d  skipped(already)=%d\n", $compressed, $skipped);
if ($rawSum > 0) {
    printf(
        "html bytes: %.1f MB -> %.1f MB  (%.0f%% reduction)\n",
        $rawSum / 1048576,
        $newSum / 1048576,
        100 - $newSum / $rawSum * 100
    );
}

if (!$dryRun && !$noVacuum && $compressed > 0) {
    echo "wal checkpoint + VACUUM...\n";
    $pdo->exec('PRAGMA wal_checkpoint(TRUNCATE)');
    $pdo->exec('VACUUM');

    clearstatcache(true, $dbPath);
    $fileAfter = filesize($dbPath);
    printf(
        "file: %.1f MB -> %.1f MB  (%.0f%% reduction)\n",
        $fileBefore / 1048576,
        $fileAfter / 1048576,
        100 - $fileAfter / $fileBefore * 100
    );
}

echo "done\n";
