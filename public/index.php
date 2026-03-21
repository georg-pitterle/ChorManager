<?php

use DI\ContainerBuilder;
use Slim\Factory\AppFactory;
use Dotenv\Dotenv;

require __DIR__ . '/../vendor/autoload.php';

$envPath = __DIR__ . '/../.env';
if (file_exists($envPath)) {
    Dotenv::createImmutable(__DIR__ . '/..')->safeLoad();
}

$secureSessionCookie = (getenv('APP_ENV') === 'production')
    || (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off');

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
// $middleware = require __DIR__ . '/../src/Middleware.php';
// $middleware($app);

// Register routes
$routes = require __DIR__ . '/../src/Routes.php';
$routes($app);

// Run app
$app->run();
