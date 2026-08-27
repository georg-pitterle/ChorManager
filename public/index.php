<?php

use DI\ContainerBuilder;
use Slim\Factory\AppFactory;
use Dotenv\Dotenv;
use App\Util\AppUrlResolver;
use App\Util\SessionConfig;
use App\Util\Timezone;

require __DIR__ . '/../vendor/autoload.php';

$envPath = __DIR__ . '/../.env';
if (file_exists($envPath)) {
    Dotenv::createImmutable(__DIR__ . '/..')->safeLoad();
}

// Fehlt im Produktivbetrieb die Basisadresse, bricht der Start ab: Jeder Link in
// einer Mail stammte sonst aus dem frei waehlbaren Host-Kopf der Anfrage. Die
// Pruefung steht bewusst hier, damit die Fehlkonfiguration sofort auffaellt und
// nicht erst beim ersten Passwort-Zuruecksetzen.
AppUrlResolver::assertConfiguredForProduction();

date_default_timezone_set(Timezone::resolveAppTimezone());

// Must run before any session is started: keeps session files out of the
// container's writable layer so a redeploy does not log every user out.
SessionConfig::applySavePath();

$secureSessionCookie = SessionConfig::shouldUseSecureCookie($_SERVER);

ini_set('session.use_only_cookies', '1');
ini_set('session.use_strict_mode', '1');
ini_set('session.cookie_httponly', '1');
ini_set('session.cookie_secure', $secureSessionCookie ? '1' : '0');
ini_set('session.cookie_samesite', 'Lax');

session_set_cookie_params([
    'lifetime' => 0,
    'path' => '/',
    'secure' => $secureSessionCookie,
    'httponly' => true,
    'samesite' => 'Lax'
]);

// Instantiate PHP-DI ContainerBuilder
$containerBuilder = new ContainerBuilder();

// Set up settings
$settings = require __DIR__ . '/../src/Settings.php';
$settings($containerBuilder);

// Set up dependencies
$dependencies = require __DIR__ . '/../src/Dependencies.php';
$dependencies($containerBuilder);

// Build PHP-DI Container instance
$container = $containerBuilder->build();

// Eager load database capsule to boot Eloquent globally
$container->get(\Illuminate\Database\Capsule\Manager::class);

// Instantiate the app
AppFactory::setContainer($container);
$app = AppFactory::create();

// Register middleware
$middleware = require __DIR__ . '/../src/Middleware.php';
$middleware($app);

// Register routes
$routes = require __DIR__ . '/../src/Routes.php';
$routes($app);

// Run app
$app->run();
