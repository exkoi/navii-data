<?php

declare(strict_types=1);

return [
    'base_url'        => 'https://www.iryou.teikyouseido.mhlw.go.jp',
    'user_agent'      => 'NaviiDataBot/0.1 (+mailto:koizumi@hospita.jp; purpose=research; contact for opt-out)',
    'contact_email'   => 'koizumi@hospita.jp',

    'sleep_min_sec'   => (int)(getenv('NAVII_SLEEP_MIN') ?: 3),
    'sleep_max_sec'   => (int)(getenv('NAVII_SLEEP_MAX') ?: 10),
    'max_per_run'     => (int)(getenv('NAVII_MAX_PER_RUN') ?: 10),

    'target_prefs'    => array_filter(explode(',', (string)(getenv('NAVII_TARGET_PREFS') ?: '13'))),
    'target_kbns'     => array_filter(explode(',', (string)(getenv('NAVII_TARGET_KBNS') ?: '2'))),

    'db_path'         => dirname(__DIR__) . '/data/navii.sqlite',
    'stop_file'       => dirname(__DIR__) . '/data/.stop',
    'lock_file'       => dirname(__DIR__) . '/data/.lock',
    'log_dir'         => dirname(__DIR__) . '/data/logs',

    'circuit_breaker_threshold' => 5,
    'max_retry'       => 3,
    'connect_timeout' => 10,
    'total_timeout'   => 30,

    'list_path'       => '/znk-web/juminkanja/S2400/initialize',
    'detail_path'     => '/znk-web/juminkanja/S2430/initialize',
    'list_sjk'        => '3',
    'list_jc'         => 'MC-01',
    'list_per_page'   => 20,
];
