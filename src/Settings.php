<?php

declare(strict_types=1);

use DI\ContainerBuilder;
use App\Util\AppEnvironment;
use App\Util\EnvHelper;
use App\Util\Timezone;

return function (ContainerBuilder $containerBuilder) {
    $appTimezone = Timezone::resolveAppTimezone();

    // Global Settings Object
    $containerBuilder->addDefinitions([
        'settings' => [
            'displayErrorDetails' => AppEnvironment::isDebugEnabled(),
            'timezone' => $appTimezone,
            'db' => [
                'driver' => 'mysql',
                'host' => EnvHelper::read('DB_HOST', 'db'),
                'database' => EnvHelper::read('DB_DATABASE', 'db'),
                'username' => EnvHelper::read('DB_USERNAME', 'db'),
                'password' => EnvHelper::read('DB_PASSWORD', 'db'),
                'timezone' => Timezone::resolveDatabaseTimezoneOffset(),
                'charset' => 'utf8mb4',
                'collation' => 'utf8mb4_unicode_ci',
                'prefix' => '',
            ],
            'view' => [
                'template_path' => __DIR__ . '/../templates',
                'cache_path' => false, // __DIR__ . '/../var/cache' for production
            ],
            'logging' => [
                'channel' => 'chormanager',
                'service' => 'chormanager',
                'environment' => AppEnvironment::current(),
                'stream' => EnvHelper::read('APP_LOG_STREAM', 'php://stderr'),
                'level' => strtoupper(EnvHelper::read('APP_LOG_LEVEL', 'INFO')),
            ],
        ],
    ]);
};
