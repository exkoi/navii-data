<?php

declare(strict_types=1);

require __DIR__ . '/vendor/autoload.php';

use Exp\NaviiData\Db;
use Exp\NaviiData\PrefMap;
use Exp\NaviiData\StatusReport;

date_default_timezone_set('Asia/Tokyo');

$config = require __DIR__ . '/config/config.php';

header('Content-Type: text/html; charset=UTF-8');
header('Cache-Control: no-store');

function h(mixed $v): string
{
    return htmlspecialchars((string)$v, ENT_QUOTES | ENT_HTML5, 'UTF-8');
}

if (!file_exists($config['db_path'])) {
    http_response_code(503);
    echo '<!doctype html><meta charset="utf-8"><title>Navii Data Scraper</title>';
    echo '<h1>DB not found</h1><p>Run <code>php bin/init-db.php</code> first.</p>';
    exit;
}

try {
    $pdo = Db::open($config['db_path'], readOnly: true, stateDbPath: $config['state_db_path']);
} catch (Throwable $e) {
    http_response_code(500);
    echo '<!doctype html><meta charset="utf-8"><title>Navii Data Scraper</title>';
    echo '<h1>DB open failed</h1><pre>', h($e->getMessage()), '</pre>';
    exit;
}

$report = (new StatusReport($pdo, $config))->collect();
$stop = $report['stop_file'];

?>
<!doctype html>
<html lang="ja">
<head>
<meta charset="utf-8">
<title>Navii Data Scraper Status</title>
</head>
<body>
<h1>Navii Data Scraper Status</h1>

<p>generated_at: <?= h($report['generated_at']) ?></p>

<p>stop_file:
<?php if ($stop['exists']): ?>
    <strong>*** PRESENT ***</strong> (<?= h($stop['path']) ?>)
<?php else: ?>
    not present
<?php endif; ?>
</p>

<h2>Config</h2>
<table border="1" cellpadding="4">
<?php foreach ($report['config_summary'] as $k => $v): ?>
    <tr>
        <th><?= h($k) ?></th>
        <td><?= h(is_array($v) ? implode(',', $v) : $v) ?></td>
    </tr>
<?php endforeach; ?>
</table>

<h2>facilities (by pref / kikan_kbn)</h2>
<?php if (!$report['facilities']): ?>
    <p>(no facilities yet)</p>
<?php else: ?>
    <table border="1" cellpadding="4">
        <tr><th>pref_cd</th><th>pref</th><th>kikan_kbn</th><th>kbn</th><th>count</th></tr>
        <?php foreach ($report['facilities'] as $r): ?>
            <tr>
                <td><?= h($r['pref_cd']) ?></td>
                <td><?= h(PrefMap::prefName((string)$r['pref_cd'])) ?></td>
                <td><?= h($r['kikan_kbn']) ?></td>
                <td><?= h(PrefMap::kbnName((string)$r['kikan_kbn'])) ?></td>
                <td><?= h($r['count']) ?></td>
            </tr>
        <?php endforeach; ?>
    </table>
<?php endif; ?>

<h2>status breakdown</h2>
<?php if (!$report['status_breakdown']): ?>
    <p>(empty)</p>
<?php else: ?>
    <table border="1" cellpadding="4">
        <tr><th>status</th><th>count</th></tr>
        <?php foreach ($report['status_breakdown'] as $r): ?>
            <tr><td><?= h($r['status']) ?></td><td><?= h($r['count']) ?></td></tr>
        <?php endforeach; ?>
    </table>
<?php endif; ?>

<h2>list_progress (per pref)</h2>
<?php if (!$report['list_progress']): ?>
    <p>(no list_progress yet)</p>
<?php else: ?>
    <table border="1" cellpadding="4">
        <tr>
            <th>pref_cd</th><th>pref</th><th>munis_started / munis_total</th>
            <th>done_pages</th><th>error_pages</th><th>last_fetched_at</th>
        </tr>
        <?php foreach ($report['list_progress'] as $r): ?>
            <tr>
                <td><?= h($r['pref_cd']) ?></td>
                <td><?= h($r['pref_name'] ?? PrefMap::prefName((string)$r['pref_cd'])) ?></td>
                <td><?= h($r['munis_started'] ?? 0) ?> / <?= h($r['munis_total'] ?? 0) ?></td>
                <td><?= h($r['done_pages'] ?? 0) ?></td>
                <td><?= h($r['error_pages'] ?? 0) ?></td>
                <td><?= h($r['last_fetched_at'] ?? '') ?></td>
            </tr>
        <?php endforeach; ?>
    </table>
<?php endif; ?>

<h2>html storage</h2>
<?php
    $hs = $report['html_storage'];
    $bytes = (int)($hs['total_html_bytes'] ?? 0);
    $mb = number_format($bytes / (1024 * 1024), 1) . ' MB';
?>
<table border="1" cellpadding="4">
    <tr><th>facilities_with_html</th><td><?= h($hs['facilities_with_html'] ?? 0) ?></td></tr>
    <tr><th>total_html_size</th><td><?= h($mb) ?></td></tr>
    <tr><th>last_scraped_at</th><td><?= h($hs['last_scraped_at'] ?? '') ?></td></tr>
    <tr><th>last_changed_at</th><td><?= h($hs['last_changed_at'] ?? '') ?></td></tr>
</table>

<h2>ダウンロード</h2>
<?php
    $snapshotPath = __DIR__ . '/public/download/navii.sqlite';
    $metaPath     = $snapshotPath . '.meta.json';
    $meta = is_file($metaPath) ? json_decode((string)file_get_contents($metaPath), true) : null;
    $baseUrl = 'https://' . ($_SERVER['HTTP_HOST'] ?? 'localhost')
             . rtrim(dirname($_SERVER['SCRIPT_NAME'] ?? '/'), '/')
             . '/public/download/navii.sqlite';
?>
<?php if (!$meta || !is_file($snapshotPath)): ?>
    <p>(スナップショット未生成。<code>php bin/make-snapshot.php</code> を手動実行するか、毎時の cron 完了を待つ)</p>
<?php else: ?>
    <table border="1" cellpadding="4">
        <tr><th>file</th><td><a href="public/download/navii.sqlite" download="navii.sqlite">navii.sqlite</a></td></tr>
        <tr><th>size</th><td><?= h(number_format((int)$meta['size_bytes'])) ?> B (<?= h(number_format($meta['size_bytes'] / 1048576, 1)) ?> MB)</td></tr>
        <tr><th>sha256</th><td><code style="font-size:0.85em"><?= h($meta['sha256']) ?></code> <a href="public/download/navii.sqlite.sha256">(.sha256)</a></td></tr>
        <tr><th>rows_with_html</th><td><?= h(number_format((int)$meta['rows_with_html'])) ?></td></tr>
        <tr><th>rows_total</th><td><?= h(number_format((int)$meta['rows_total'])) ?></td></tr>
        <tr><th>generated_at</th><td><?= h($meta['generated_at']) ?></td></tr>
        <tr><th>last_scraped_at</th><td><?= h($meta['last_scraped_at'] ?? '') ?></td></tr>
    </table>
    <p><strong>推奨：curl でレジューム可能 DL → SHA-256 照合</strong></p>
<pre>curl -u USER:PASS --fail -C - -o navii.sqlite \
  "<?= h($baseUrl) ?>"
shasum -a 256 navii.sqlite   # 上の sha256 と一致するか確認
sqlite3 navii.sqlite "PRAGMA integrity_check;"   # ok を確認</pre>
<?php endif; ?>

<h2>recent fetches (latest 20)</h2>
<?php if (!$report['recent_fetches']): ?>
    <p>(no fetches yet)</p>
<?php else: ?>
    <table border="1" cellpadding="4">
        <tr>
            <th>fetched_at</th><th>status</th><th>bytes</th><th>duration_ms</th><th>url</th><th>error</th>
        </tr>
        <?php foreach ($report['recent_fetches'] as $r): ?>
            <tr>
                <td><?= h($r['fetched_at']) ?></td>
                <td><?= h($r['http_status'] ?? '') ?></td>
                <td><?= h($r['bytes'] ?? '') ?></td>
                <td><?= h($r['duration_ms'] ?? '') ?></td>
                <td><?= h($r['url']) ?></td>
                <td><?= h($r['error'] ?? '') ?></td>
            </tr>
        <?php endforeach; ?>
    </table>
<?php endif; ?>

</body>
</html>
