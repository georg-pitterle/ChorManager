<?php

declare(strict_types=1);

use DI\ContainerBuilder;
use App\Util\EnvHelper;

return function (ContainerBuilder $containerBuilder) {
    // Global Settings Object
    $containerBuilder->addDefinitions([
        'settings' => [
            'displayErrorDetails' => EnvHelper::read('APP_ENV', 'development') !== 'production', // Set to false in production
            'db' => [
                'driver' => 'mysql',
                'host' => EnvHelper::read('DB_HOST', 'db'),
                'database' => EnvHelper::read('DB_DATABASE', 'db'),
                'username' => EnvHelper::read('DB_USERNAME', 'db'),
                'password' => EnvHelper::read('DB_PASSWORD', 'db'),
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
