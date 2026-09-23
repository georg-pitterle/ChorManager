<?php
return [
    'paths' => [
        'migrations' => 'db/migrations',
        'seeds' => 'db/seeds'
    ],
    'environments' => [
        'default_migration_table' => 'phinxlog',
        'default_environment' => 'development',
        // Configuration; override credentials as needed
        'development' => [

            'adapter'  => 'mysql',
            'host'     => getenv('DB_HOST') ?: 'db',
            'name'     => getenv('DB_DATABASE') ?: 'db',
            'user'     => getenv('DB_USERNAME') ?: 'db',
            'pass'     => getenv('DB_PASSWORD') ?: 'db',
            'port'     => getenv('DB_PORT') ?: '3306',
            'charset'  => 'utf8mb4',
            // Ohne diese Zeile setzt Phinx seine eigene Vorgabe
            // (utf8mb4_unicode_ci) und legt neue Tabellen neben dem
            // übrigen Schema an - siehe 20260923120000.
            'collation' => 'utf8mb4_general_ci',
        ],
    ],
];
