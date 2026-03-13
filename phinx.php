<?php
return [
    'paths' => [
        'migrations' => 'db/migrations',
        'seeds' => 'db/seeds'
    ],
    'environments' => [
        'default_migration_table' => 'phinxlog',
        'default_environment' => 'mysql',
        // MySQL environment configuration; override credentials as needed
        'mysql' => [
            'adapter'  => 'mysql',
            'host'     => getenv('DB_HOST') ?: 'db',
            'name'     => getenv('DB_DATABASE') ?: 'db',
            'user'     => getenv('DB_USER') ?: 'db',
            'pass'     => getenv('DB_PASSWORD') ?: 'db',
            'port'     => getenv('DB_PORT') ?: '3306',
            'charset'  => 'utf8mb4',
        ],
    ],
];
