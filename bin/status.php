<?php

declare(strict_types=1);

require __DIR__ . '/../vendor/autoload.php';

date_default_timezone_set('Asia/Tokyo');

use Exp\NaviiData\Db;
use Exp\NaviiData\PrefMap;
use Exp\NaviiData\StatusReport;

$config = require __DIR__ . '/../config/config.php';

if (!file_exists($config['db_path'])) {
    fwrite(STDERR, "DB not found. Run: php bin/init-db.php\n");
    exit(1);
}

$pdo = Db::open($config['db_path'], readOnly: true, stateDbPath: $config['state_db_path']);
$report = (new StatusReport($pdo, $config))->collect();

echo "=== Navii Data Scraper Status ===\n";
echo "generated_at: {$report['generated_at']}\n";

$stop = $report['stop_file'];
echo "stop_file: " . ($stop['exists'] ? "*** PRESENT *** ({$stop['path']})" : 'not present') . "\n";

echo "\n[Config]\n";
foreach ($report['config_summary'] as $k => $v) {
    if (is_array($v)) {
        $v = implode(',', $v);
    }
    echo "  {$k}: {$v}\n";
}

echo "\n[facilities by pref/kbn]\n";
foreach ($report['facilities'] as $r) {
    printf("  %-12s (%s) %-12s (%s) : %d\n",
        $r['pref_cd'], PrefMap::prefName((string)$r['pref_cd']),
        $r['kikan_kbn'], PrefMap::kbnName((string)$r['kikan_kbn']),
        $r['count'],
    );
}

echo "\n[status breakdown]\n";
foreach ($report['status_breakdown'] as $r) {
    printf("  %-10s : %d\n", $r['status'], $r['count']);
}

echo "\n[list_progress (per pref)]\n";
foreach ($report['list_progress'] as $r) {
    printf("  %s (%s) munis=%s/%s done_pages=%s error_pages=%s last=%s\n",
        $r['pref_cd'],
        $r['pref_name'] ?? PrefMap::prefName((string)$r['pref_cd']),
        $r['munis_started'] ?? 0,
        $r['munis_total'] ?? 0,
        $r['done_pages'] ?? 0,
        $r['error_pages'] ?? 0,
        $r['last_fetched_at'] ?? 'null',
    );
}

echo "\n[html storage]\n";
$h = $report['html_storage'];
$bytes = (int)($h['total_html_bytes'] ?? 0);
printf("  facilities_with_html=%s  total_html=%.1fMB  last_scraped=%s  last_changed=%s\n",
    $h['facilities_with_html'] ?? 0,
    $bytes / (1024 * 1024),
    $h['last_scraped_at'] ?? 'null',
    $h['last_changed_at'] ?? 'null',
);

echo "\n[recent fetch_log (latest 20)]\n";
foreach ($report['recent_fetches'] as $r) {
    printf("  %s status=%-4s bytes=%-7s dur=%-5sms %s%s\n",
        $r['fetched_at'],
        $r['http_status'] ?? '-',
        $r['bytes'] ?? '-',
        $r['duration_ms'] ?? '-',
        substr((string)$r['url'], 0, 100),
        $r['error'] ? ' err=' . $r['error'] : '',
    );
}
