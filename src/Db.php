<?php

declare(strict_types=1);

namespace Exp\NaviiData;

use PDO;
use RuntimeException;

final class Db
{
    private function __construct() {}

    public static function open(string $path, bool $readOnly = false, ?string $stateDbPath = null): PDO
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
            // PHP 8.5 で PDO::SQLITE_* が deprecated、Pdo\Sqlite::* が導入された。
            // 8.4 以下では Pdo\Sqlite クラスが存在しないので、定数の有無で振り分ける。
            $attrOpenFlags = defined('Pdo\Sqlite::ATTR_OPEN_FLAGS')
                ? constant('Pdo\Sqlite::ATTR_OPEN_FLAGS')
                : PDO::SQLITE_ATTR_OPEN_FLAGS;
            $openReadOnly = defined('Pdo\Sqlite::OPEN_READONLY')
                ? constant('Pdo\Sqlite::OPEN_READONLY')
                : PDO::SQLITE_OPEN_READONLY;
            $options[$attrOpenFlags] = $openReadOnly;
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

        if ($stateDbPath !== null) {
            self::attachState($pdo, $stateDbPath, $readOnly);
        }

        return $pdo;
    }

    public static function attachState(PDO $pdo, string $stateDbPath, bool $readOnly = false): void
    {
        if (!$readOnly && !is_dir(dirname($stateDbPath))) {
            if (!@mkdir(dirname($stateDbPath), 0775, true) && !is_dir(dirname($stateDbPath))) {
                throw new RuntimeException('failed to create state DB directory: ' . dirname($stateDbPath));
            }
        }
        if ($readOnly && !file_exists($stateDbPath)) {
            throw new RuntimeException('state DB file not found for read-only attach: ' . $stateDbPath);
        }

        $escaped = str_replace("'", "''", $stateDbPath);
        $pdo->exec("ATTACH DATABASE '{$escaped}' AS state");

        if (!$readOnly) {
            $pdo->exec('PRAGMA state.journal_mode = WAL');
            $pdo->exec('PRAGMA state.synchronous = NORMAL');
        }
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
