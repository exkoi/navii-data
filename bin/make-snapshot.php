#!/usr/bin/env php
<?php

declare(strict_types=1);

/**
 * DL配信用スナップショットを生成する。
 *
 * 本DBを直接Web配信すると、クロール書き込みと衝突して中途半端なファイルが
 * クライアントに渡る可能性がある。SQLite の Online Backup API で別ファイルへ
 * 整合性スナップショットを作り、それを静的配信する。
 *
 * 流れ:
 *   1. flock で多重起動防止
 *   2. SQLite3::backup() で tmp ファイルにスナップショット（本DB書き込みと並走OK）
 *   3. tmp に対して PRAGMA quick_check（NG なら配信しない）
 *   4. sha256 算出・meta.json 生成
 *   5. atomic rename で公開ファイルを差し替え（中途半端な状態を晒さない）
 *
 * cron 例（Xserver の負荷監視に配慮して nice/ionice で優先度を下げる）:
 *   17 *\/6 * * * cd /path/to/navii-data && nice -n 19 ionice -c 3 /usr/bin/php8.5 bin/make-snapshot.php >> data/logs/cron-snapshot.log 2>&1
 */

require __DIR__ . '/../vendor/autoload.php';

date_default_timezone_set('Asia/Tokyo');

$config = require __DIR__ . '/../config/config.php';

$src      = $config['db_path'];
$dataDir  = dirname($src);
$lockFile = $dataDir . '/.snapshot.lock';

$publicDir = __DIR__ . '/../public/download';
$dst       = $publicDir . '/navii.sqlite';
$tmp       = $dst . '.tmp';

if (!is_file($src)) {
    fwrite(STDERR, "source DB not found: {$src}\n");
    exit(1);
}

if (!is_dir($publicDir) && !mkdir($publicDir, 0755, true) && !is_dir($publicDir)) {
    fwrite(STDERR, "failed to create {$publicDir}\n");
    exit(1);
}

$lockHandle = fopen($lockFile, 'c');
if ($lockHandle === false) {
    fwrite(STDERR, "failed to open lock file: {$lockFile}\n");
    exit(1);
}
if (!flock($lockHandle, LOCK_EX | LOCK_NB)) {
    fwrite(STDERR, "[" . date('c') . "] another snapshot in progress, skip\n");
    exit(0);
}

$start = microtime(true);

try {
    // 前回失敗時の残骸を掃除（VACUUM INTO は出力先既存だと失敗する）
    if (is_file($tmp)) {
        unlink($tmp);
    }

    // 1) 整合性スナップショットを別ファイルに書き出す。
    //    SQLite の Online Backup API を ext-sqlite3 経由で呼ぶ。
    //    VACUUM INTO は 3.27.0+ 限定なので、共有レンタル等で古い SQLite が
    //    入っていても動くよう backup() を使う。本DBへの書き込みは並走可能。
    $srcSql = new SQLite3($src, SQLITE3_OPEN_READONLY);
    $dstSql = new SQLite3($tmp, SQLITE3_OPEN_CREATE | SQLITE3_OPEN_READWRITE);
    try {
        if (!$srcSql->backup($dstSql)) {
            throw new RuntimeException('SQLite3::backup() returned false');
        }
    } finally {
        $dstSql->close();
        $srcSql->close();
    }

    if (!is_file($tmp)) {
        throw new RuntimeException('VACUUM INTO completed but output file missing');
    }

    // 2) 出来上がった tmp に対して整合性チェック。ok でなければ絶対に公開しない。
    //    integrity_check はテーブル全行スキャンで重く、Xserver の負荷監視に
    //    引っかかりやすいので quick_check（インデックス検査をスキップ）を使う。
    //    SQLite3::backup() でページ単位コピーした直後のファイルが対象なので、
    //    壊れるとしたら構造レベルで quick_check で十分検出できる。
    $tmpPdo = new PDO('sqlite:' . $tmp);
    $tmpPdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    $check  = (string)$tmpPdo->query('PRAGMA quick_check')->fetchColumn();
    if ($check !== 'ok') {
        $tmpPdo = null;
        @unlink($tmp);
        throw new RuntimeException("quick_check failed: {$check}");
    }

    $rowsTotal     = (int)$tmpPdo->query('SELECT count(*) FROM facilities')->fetchColumn();
    $rowsWithHtml  = (int)$tmpPdo->query('SELECT count(*) FROM facilities WHERE html IS NOT NULL')->fetchColumn();
    $lastScrapedAt = (string)$tmpPdo->query('SELECT max(last_scraped_at) FROM facilities WHERE last_scraped_at IS NOT NULL')->fetchColumn();
    $lastChangedAt = (string)$tmpPdo->query('SELECT max(last_changed_at) FROM facilities WHERE last_changed_at IS NOT NULL')->fetchColumn();
    $tmpPdo = null;

    // 3) sha256 算出。
    $sha256 = hash_file('sha256', $tmp);
    if ($sha256 === false) {
        throw new RuntimeException('hash_file failed');
    }
    $size = filesize($tmp);

    // 4) 公開ファイルを atomic に差し替え。同一FS上の rename は原子的。
    if (!rename($tmp, $dst)) {
        throw new RuntimeException("rename failed: {$tmp} -> {$dst}");
    }

    file_put_contents($dst . '.sha256', "{$sha256}  navii.sqlite\n");
    file_put_contents(
        $dst . '.meta.json',
        json_encode([
            'generated_at'    => date('c'),
            'size_bytes'      => $size,
            'sha256'          => $sha256,
            'rows_total'      => $rowsTotal,
            'rows_with_html'  => $rowsWithHtml,
            'last_scraped_at' => $lastScrapedAt,
            'last_changed_at' => $lastChangedAt,
            'duration_sec'    => round(microtime(true) - $start, 2),
        ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . "\n"
    );

    printf(
        "[%s] snapshot ok: %.1f MB in %.1fs (rows_with_html=%d sha256=%s)\n",
        date('c'),
        $size / 1048576,
        microtime(true) - $start,
        $rowsWithHtml,
        substr($sha256, 0, 12)
    );
} catch (Throwable $e) {
    fwrite(STDERR, '[' . date('c') . '] snapshot failed: ' . $e->getMessage() . "\n");
    if (is_file($tmp)) {
        @unlink($tmp);
    }
    exit(1);
} finally {
    flock($lockHandle, LOCK_UN);
    fclose($lockHandle);
}
