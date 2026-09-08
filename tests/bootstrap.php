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

// Die Testverbindung liest DB_DATABASE stumm aus der Umgebung. Zeigt der Wert auf einen
// echten Bestand, schreibt und leert die Suite dort - deshalb vor jedem Verbindungsaufbau
// die Notbremse. Sie steht hier und nicht in Tests\Unit\Bootstrap, weil fünfzehn
// Testklassen ihre Verbindung selbst aufbauen und an einer Prüfung dort vorbeilaufen.
$testDatabase = $_ENV['DB_DATABASE'] ?? $_SERVER['DB_DATABASE'] ?? null;
$allowForeignDatabase = $_ENV['ALLOW_NON_TEST_DATABASE'] ?? $_SERVER['ALLOW_NON_TEST_DATABASE'] ?? null;
Tests\Support\TestDatabaseGuard::assertTestDatabase(
    $testDatabase === null ? null : (string) $testDatabase,
    $allowForeignDatabase === null ? null : (string) $allowForeignDatabase
);

// Load shared test helpers that are not autoloaded via Composer.
require_once __DIR__ . '/Feature/TestHttpHelpers.php';
require_once __DIR__ . '/Feature/TwigViewStubs.php';

// Zählstand vor dem ersten Test. tests/Guard/DatabaseLeakGuardTest vergleicht damit am
// Ende des Laufs und meldet jeden Test, der Zeilen zurücklässt. Fehlt die Datenbank,
// bleibt der Ausgangsstand leer und der Wächter überspringt sich selbst - ein
// Verbindungsfehler soll nicht schon hier den ganzen Lauf abbrechen.
try {
    Tests\Unit\Bootstrap::setupTestDatabase();
    Tests\Support\DatabaseRowCountSnapshot::captureBaseline();
} catch (Throwable $baselineError) {
    // Grund festhalten statt verschlucken: fehlt die Datenbank, ist das Überspringen
    // richtig - scheitert dagegen die Abfrage auf information_schema an fehlenden
    // Rechten, soll das im Skip-Text stehen und nicht unbemerkt bleiben.
    Tests\Support\DatabaseRowCountSnapshot::recordBaselineFailure($baselineError);
}
