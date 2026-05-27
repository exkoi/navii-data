<?php

declare(strict_types=1);

namespace Exp\NaviiData;

use PDO;

final class StatusReport
{
    public function __construct(
        private PDO $pdo,
        /** @var array<string, mixed> */
        private array $config,
    ) {}

    /** @return array<string, mixed> */
    public function collect(): array
    {
        return [
            'generated_at'    => date('Y-m-d H:i:sP'),
            'stop_file'       => [
                'path'   => (string)$this->config['stop_file'],
                'exists' => file_exists((string)$this->config['stop_file']),
            ],
            'config_summary'  => [
                'target_prefs'  => array_values((array)$this->config['target_prefs']),
                'target_kbns'   => array_values((array)$this->config['target_kbns']),
                'sleep_min_sec' => (int)$this->config['sleep_min_sec'],
                'sleep_max_sec' => (int)$this->config['sleep_max_sec'],
                'max_per_run'   => (int)$this->config['max_per_run'],
            ],
            'facilities'      => $this->facilities(),
            'status_breakdown'=> $this->statusBreakdown(),
            'list_progress'   => $this->listProgress(),
            'html_storage'    => $this->htmlStorage(),
            'recent_fetches'  => $this->recentFetches(20),
        ];
    }

    /** @return array<int, array<string, mixed>> */
    private function facilities(): array
    {
        return $this->pdo->query(
            'SELECT pref_cd, kikan_kbn, COUNT(*) AS count
             FROM facilities
             GROUP BY pref_cd, kikan_kbn
             ORDER BY pref_cd, kikan_kbn'
        )->fetchAll();
    }

    /** @return array<int, array<string, mixed>> */
    private function statusBreakdown(): array
    {
        return $this->pdo->query(
            'SELECT status, COUNT(*) AS count
             FROM facilities
             GROUP BY status
             ORDER BY status'
        )->fetchAll();
    }

    /** @return array<int, array<string, mixed>> */
    private function listProgress(): array
    {
        return $this->pdo->query(
            'SELECT m.pref_cd,
                    m.pref_name,
                    COUNT(DISTINCT lp.lo) AS munis_started,
                    (SELECT COUNT(*) FROM state.municipalities
                       WHERE pref_cd = m.pref_cd AND admin_class != "DesignatedCity") AS munis_total,
                    SUM(CASE WHEN lp.status = "done" THEN 1 ELSE 0 END) AS done_pages,
                    SUM(CASE WHEN lp.status = "error" THEN 1 ELSE 0 END) AS error_pages,
                    MAX(lp.fetched_at) AS last_fetched_at
             FROM state.list_progress lp
             JOIN state.municipalities m ON m.lo = lp.lo
             GROUP BY m.pref_cd, m.pref_name
             ORDER BY m.pref_cd'
        )->fetchAll();
    }

    /** @return array<string, mixed> */
    private function htmlStorage(): array
    {
        $row = $this->pdo->query(
            'SELECT COUNT(*) AS facilities_with_html,
                    SUM(LENGTH(html)) AS total_html_bytes,
                    MAX(last_scraped_at) AS last_scraped_at,
                    MAX(last_changed_at) AS last_changed_at
             FROM facilities
             WHERE html IS NOT NULL'
        )->fetch();
        return $row ?: [
            'facilities_with_html' => 0,
            'total_html_bytes'     => 0,
            'last_scraped_at'      => null,
            'last_changed_at'      => null,
        ];
    }

    /** @return array<int, array<string, mixed>> */
    private function recentFetches(int $limit): array
    {
        $stmt = $this->pdo->prepare(
            'SELECT fetched_at, http_status, bytes, duration_ms, url, error
             FROM state.fetch_log
             ORDER BY id DESC
             LIMIT :lim'
        );
        $stmt->bindValue(':lim', $limit, PDO::PARAM_INT);
        $stmt->execute();
        return $stmt->fetchAll();
    }
}
