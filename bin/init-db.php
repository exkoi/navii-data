<?php

declare(strict_types=1);

require __DIR__ . '/../vendor/autoload.php';

date_default_timezone_set('Asia/Tokyo');

use Exp\NaviiData\Db;

$config = require __DIR__ . '/../config/config.php';

$pdo = Db::open($config['db_path']);
Db::migrate($pdo, __DIR__ . '/../migrations');
fwrite(STDOUT, "main DB initialized: {$config['db_path']}\n");

$statePdo = Db::open($config['state_db_path']);
Db::migrate($statePdo, __DIR__ . '/../migrations-state');
fwrite(STDOUT, "state DB initialized: {$config['state_db_path']}\n");
