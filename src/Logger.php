<?php

declare(strict_types=1);

namespace Exp\NaviiData;

use PDO;

final class Logger
{
    private ?PDO $pdo = null;
    private string $logFile;

    public function __construct(string $logDir, string $name)
    {
        if (!is_dir($logDir)) {
            @mkdir($logDir, 0775, true);
        }
        $this->logFile = $logDir . '/' . $name . '.log';
    }

    public function attachPdo(PDO $pdo): void
    {
        $this->pdo = $pdo;
    }

    public function info(string $msg): void
    {
        $this->write('INFO', $msg);
    }

    public function warn(string $msg): void
    {
        $this->write('WARN', $msg);
    }

    public function error(string $msg): void
    {
        $this->write('ERROR', $msg);
    }

    private function write(string $level, string $msg): void
    {
        $line = sprintf("[%s] %s %s\n", date('Y-m-d H:i:sP'), $level, $msg);
        @file_put_contents($this->logFile, $line, FILE_APPEND);
        fwrite(STDOUT, $line);
    }

    public function recordFetch(
        string $url,
        ?int $status,
        ?int $bytes,
        int $durationMs,
        string $userAgent,
        ?string $error,
    ): void {
        if ($this->pdo === null) {
            return;
        }
        $stmt = $this->pdo->prepare(
            'INSERT INTO state.fetch_log (url, http_status, bytes, duration_ms, user_agent, error)
             VALUES (:url, :status, :bytes, :duration, :ua, :error)'
        );
        $stmt->execute([
            ':url'      => $url,
            ':status'   => $status,
            ':bytes'    => $bytes,
            ':duration' => $durationMs,
            ':ua'       => $userAgent,
            ':error'    => $error,
        ]);
    }
}
