// Wohin ein E2E-Lauf zeigt. Die Suite läuft gegen einen eigenen DDEV-Host, nicht gegen die
// Entwicklungsinstanz: .ddev/nginx_full/nginx-site.conf gibt e2e.chormanager.ddev.site eine
// eigene Datenbank, einen eigenen Dateiablageordner und eine eigene APP_URL. So setzt der
// globalSetup nur diese Datenbank zurück, und die Dev-Daten bleiben unangetastet.
//
// Die Werte hier müssen zu denen in der nginx-Konfiguration passen.

export const BASE_URL = 'https://e2e.chormanager.ddev.site';

// Der Name fällt unter das Muster db_test%, auf dem der Anwendungsbenutzer Datenbanken
// anlegen darf (.ddev/grant-test-databases.sh).
export const E2E_DATABASE = 'db_test_e2e';

export const E2E_FILES_STORAGE_PATH = '/var/www/html/var/files-e2e';

// Für CLI-Aufrufe im Container (ddev exec), die dieselbe Instanz wie der E2E-Host sehen sollen.
export const E2E_CLI_ENV = `env DB_DATABASE=${E2E_DATABASE} FILES_STORAGE_PATH=${E2E_FILES_STORAGE_PATH} APP_URL=${BASE_URL}`;
