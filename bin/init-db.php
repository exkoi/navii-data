<?php

declare(strict_types=1);

require __DIR__ . '/../vendor/autoload.php';

date_default_timezone_set('Asia/Tokyo');

use Exp\NaviiData\Db;

$config = require __DIR__ . '/../config/config.php';

$pdo = Db::open($config['db_path']);
Db::migrate($pdo, __DIR__ . '/../migrations');

fwrite(STDOUT, "DB initialized: {$config['db_path']}\n");
