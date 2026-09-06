<?php

declare(strict_types=1);

// Set up environment
$_ENV['APP_ENV'] = $_SERVER['APP_ENV'] = 'test';
$_SERVER['HTTP_HOST'] = 'localhost';

// Load autoloader
require dirname(__DIR__) . '/vendor/autoload.php';

// Dieselbe Zeitzone wie im Web-Einstieg (public/index.php) und in den CLI-Skripten
// (bin/bootstrap_cli.php). Ohne das lief PHPUnit in der Zeitzone aus der php.ini (UTC),
// während Seed und Oberfläche ihre Zeitstempel in der App-Zeitzone schreiben: ein im
// Test angelegter Datensatz galt damit als älter als bereits vorhandene Daten und fiel
// aus Ergebnislisten heraus, die nach Zeit sortieren und seitenweise ausliefern.
$dotenvPath = dirname(__DIR__);
if (file_exists($dotenvPath . '/.env')) {
    Dotenv\Dotenv::createImmutable($dotenvPath)->safeLoad();
}
date_default_timezone_set(App\Util\Timezone::resolveAppTimezone());

// Load shared test helpers that are not autoloaded via Composer.
require_once __DIR__ . '/Feature/TestHttpHelpers.php';
require_once __DIR__ . '/Feature/TwigViewStubs.php';
