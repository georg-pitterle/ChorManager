<?php

use DI\ContainerBuilder;
use Slim\Factory\AppFactory;

require __DIR__ . '/../vendor/autoload.php';

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
