<?php
declare(strict_types = 1)
;

use DI\ContainerBuilder;

return function (ContainerBuilder $containerBuilder) {
    // Global Settings Object
    $containerBuilder->addDefinitions([
        'settings' => [
            'displayErrorDetails' => getenv('APP_ENV') !== 'production', // Set to false in production
            'db' => [
                'driver' => 'mysql',
                'host' => $_ENV['DB_HOST'] ?? 'db',
                'database' => $_ENV['DB_DATABASE'] ?? 'db',
                'username' => $_ENV['DB_USERNAME'] ?? 'db',
                'password' => $_ENV['DB_PASSWORD'] ?? 'db',
                'charset' => 'utf8mb4',
                'collation' => 'utf8mb4_unicode_ci',
                'prefix' => '',
            ],
            'view' => [
                'template_path' => __DIR__ . '/../templates',
                'cache_path' => false, // __DIR__ . '/../var/cache' for production
            ],
        ],
    ]);
};
