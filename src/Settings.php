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
                // Kein 'timezone'-Eintrag: der Connector würde daraus ein
                // SET time_zone='<fixer Offset>' bauen und die benannte Zeitzone aus den
                // Verbindungsoptionen wieder überschreiben.
                'options' => Timezone::databaseConnectionOptions(),
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
                'registration'  => EnvHelper::read('FEATURE_REGISTRATION', 'false') === 'true',
                // OpenID-Connect-Provider: Ist er aus, werden seine Routen gar
                // nicht erst registriert - ein Discovery-Dokument ohne
                // hinterlegten Client und Signierschlüssel hätte niemandem
                // genützt und stünde offen im Netz.
                'oidc'          => EnvHelper::read('FEATURE_OIDC', 'false') === 'true',
                'files'         => EnvHelper::read('FEATURE_FILES', 'false') === 'true',
                // Office-Dokumente im Browser (Collabora, WOPI). Ohne Dateiablage
                // gibt es nichts zu bearbeiten, ohne Server-Adresse keinen Editor.
                'office'        => EnvHelper::read('FEATURE_OFFICE', 'false') === 'true'
                    && EnvHelper::read('FEATURE_FILES', 'false') === 'true'
                    && trim((string) EnvHelper::read('OFFICE_SERVER_URL', '')) !== '',
            ],
            // Dateiverwaltung: Die Dateien liegen auf der Platte, nicht in der
            // Datenbank. Das Verzeichnis muss im Betrieb persistent sein.
            'files' => [
                'storage_path' => EnvHelper::read('FILES_STORAGE_PATH', __DIR__ . '/../var/files'),
                'max_upload_bytes' => (int) EnvHelper::read('FILES_MAX_UPLOAD_MB', '90') * 1024 * 1024,
                'max_versions' => max(1, (int) EnvHelper::read('FILES_MAX_VERSIONS', '10')),
                'trash_days' => max(1, (int) EnvHelper::read('FILES_TRASH_DAYS', '30')),
                // 0 heißt: kein Gesamtlimit, nur die Kontingente der Teamordner.
                'total_quota_bytes' => (int) EnvHelper::read('FILES_TOTAL_QUOTA_MB', '0') * 1024 * 1024,
                'max_zip_bytes' => (int) EnvHelper::read('FILES_MAX_ZIP_MB', '500') * 1024 * 1024,
            ],
            // Office-Anbindung: drei Adressen, weil Browser, ChorManager und
            // Collabora einander je nach Betrieb unterschiedlich erreichen.
            'office' => [
                'server_url' => EnvHelper::read('OFFICE_SERVER_URL', ''),
                'internal_url' => EnvHelper::read('OFFICE_SERVER_INTERNAL_URL', ''),
                'wopi_base_url' => EnvHelper::read('OFFICE_WOPI_BASE_URL', ''),
                'discovery_cache' => __DIR__ . '/../var/cache/office-discovery.json',
            ],
            'backup' => [
                'dir' => EnvHelper::read('BACKUP_DIR', __DIR__ . '/../var/backups'),
                'max_manual' => (int) EnvHelper::read('BACKUP_MAX_MANUAL', '5'),
                'max_auto' => (int) EnvHelper::read('BACKUP_MAX_AUTO', '7'),
                'gzip' => EnvHelper::readBool('BACKUP_GZIP', true),
                'app_version' => EnvHelper::read('APP_VERSION', 'dev'),
            ],
            // Speicherplatz-Übersicht: Kurzfassung für die Dashboard-Kachel und das
            // Verzeichnis, dessen übrige Unterordner unter "Sonstiges" erscheinen.
            'storage' => [
                'summary_cache' => __DIR__ . '/../var/cache/storage-usage-summary.json',
                'var_dir' => __DIR__ . '/../var',
            ],
        ],
    ]);
};
