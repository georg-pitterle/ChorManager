<?php

declare(strict_types=1);

// Set up environment
$_ENV['APP_ENV'] = $_SERVER['APP_ENV'] = 'test';
$_SERVER['HTTP_HOST'] = 'localhost';

// Load autoloader
require dirname(__DIR__) . '/vendor/autoload.php';

// Load shared test helpers that are not autoloaded via Composer.
require_once __DIR__ . '/Feature/TestHttpHelpers.php';
