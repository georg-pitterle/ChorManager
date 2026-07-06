<?php

declare(strict_types=1);

use DI\ContainerBuilder;
use App\Util\AppEnvironment;
use App\Util\EnvHelper;
use App\Util\Timezone;

return function (ContainerBuilder $containerBuilder) {
    $appTimezone = Timezone::resolveAppTimezone();
    $financeEnabled = EnvHelper::read('FEATURE_FINANCE', 'false') === 'true';

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
            'modules' => [
                'sheet_archive' => EnvHelper::read('FEATURE_SHEET_ARCHIVE', 'false') === 'true',
                'finance'       => $financeEnabled,
                // Budget baut auf dem Finanzmodul auf und bleibt ohne dieses deaktiviert.
                'budget'        => EnvHelper::read('FEATURE_BUDGET', 'false') === 'true' && $financeEnabled,
                'webmail'       => EnvHelper::read('FEATURE_WEBMAIL', 'false') === 'true',
                'newsletter'    => EnvHelper::read('FEATURE_NEWSLETTER', 'false') === 'true',
                'sponsoring'    => EnvHelper::read('FEATURE_SPONSORING', 'false') === 'true',
                'tasks'         => EnvHelper::read('FEATURE_TASKS', 'false') === 'true',
            ],
            'backup' => [
                'dir' => EnvHelper::read('BACKUP_DIR', __DIR__ . '/../var/backups'),
                'max_manual' => (int) EnvHelper::read('BACKUP_MAX_MANUAL', '5'),
                'max_auto' => (int) EnvHelper::read('BACKUP_MAX_AUTO', '7'),
                'gzip' => EnvHelper::readBool('BACKUP_GZIP', true),
                'app_version' => EnvHelper::read('APP_VERSION', 'dev'),
            ],
        ],
    ]);
};
