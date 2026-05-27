<?php

declare(strict_types=1);

namespace Exp\NaviiData;

use PDO;
use RuntimeException;

final class Db
{
    private function __construct() {}

    public static function open(string $path, bool $readOnly = false): PDO
    {
        if (!$readOnly && !is_dir(dirname($path))) {
            if (!@mkdir(dirname($path), 0775, true) && !is_dir(dirname($path))) {
                throw new RuntimeException('failed to create DB directory: ' . dirname($path));
            }
        }

        $dsn = 'sqlite:' . $path;
        $options = [
            PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_EMULATE_PREPARES   => false,
        ];

        if ($readOnly) {
            if (!file_exists($path)) {
                throw new RuntimeException('DB file not found for read-only open: ' . $path);
            }
            $options[PDO::SQLITE_ATTR_OPEN_FLAGS] = PDO::SQLITE_OPEN_READONLY;
        }

        $pdo = new PDO($dsn, null, null, $options);

        if (!$readOnly) {
            $pdo->exec('PRAGMA journal_mode = WAL');
            $pdo->exec('PRAGMA synchronous = NORMAL');
            $pdo->exec('PRAGMA foreign_keys = ON');
            $pdo->exec('PRAGMA busy_timeout = 5000');
        } else {
            $pdo->exec('PRAGMA query_only = ON');
        }

        return $pdo;
    }

    public static function migrate(PDO $pdo, string $migrationsDir): void
    {
        $files = glob($migrationsDir . '/*.sql') ?: [];
        sort($files);
        foreach ($files as $file) {
            $sql = file_get_contents($file);
            if ($sql === false) {
                throw new RuntimeException('failed to read migration: ' . $file);
            }
            $pdo->exec($sql);
        }
    }
}
