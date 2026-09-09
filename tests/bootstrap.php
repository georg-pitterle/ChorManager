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

// Die Datenbank des Laufs kommt nicht aus der .env, sondern wird aus ihr abgeleitet:
// "db" wird zu "db_test", bei parallelen Prozessen zu "db_test_2" und so weiter. Vorher
// lief die Suite auf demselben Bestand, den die Entwicklung im Browser benutzt - und
// zwei gleichzeitige Läufe liefen auf demselben. Sichtbar wurde das als sporadisch roter
// DatabaseLeakGuardTest: sein Zählstand am Ende passte nicht mehr zum Ausgangsstand,
// weil ein fremder Lauf dazwischen Zeilen angelegt und wieder entfernt hatte.
$allowForeignDatabase = $_ENV['ALLOW_NON_TEST_DATABASE'] ?? $_SERVER['ALLOW_NON_TEST_DATABASE'] ?? null;
$allowForeignDatabase = $allowForeignDatabase === null ? null : (string) $allowForeignDatabase;
$configuredDatabase = (string) ($_ENV['DB_DATABASE'] ?? $_SERVER['DB_DATABASE'] ?? '');
$rawWorkerToken = $_ENV['TEST_TOKEN'] ?? $_SERVER['TEST_TOKEN'] ?? null;
$workerToken = $rawWorkerToken === null ? null : (string) $rawWorkerToken;

if (Tests\Support\TestDatabaseGuard::isOverridden($allowForeignDatabase)) {
    // Wer bewusst übersteuert, bekommt genau die Datenbank, die er angegeben hat.
    $testDatabase = $configuredDatabase;
} else {
    $testDatabase = Tests\Support\TestDatabaseName::forRun($configuredDatabase, $workerToken);

    // Der abgeleitete Name muss in der Umgebung stehen, bevor irgendetwas eine Verbindung
    // aufbaut: fünfzehn Testklassen bauen ihre eigene auf und lesen dieselben Werte.
    putenv('DB_DATABASE=' . $testDatabase);
    $_ENV['DB_DATABASE'] = $testDatabase;
    $_SERVER['DB_DATABASE'] = $testDatabase;
}

// Die Notbremse bleibt: zeigt der Wert trotzdem auf einen echten Bestand, schreibt und
// leert die Suite dort. Sie steht hier und nicht in Tests\Unit\Bootstrap, weil die
// selbstverbindenden Testklassen an einer Prüfung dort vorbeilaufen.
Tests\Support\TestDatabaseGuard::assertTestDatabase($testDatabase, $allowForeignDatabase);

// Die abgeleitete Datenbank gibt es beim ersten Lauf noch nicht, und nach einer neuen
// Migration ist sie veraltet. Beides holt das Vorbereitungsskript nach; es ist
// wiederholbar und kostet auf einem aktuellen Stand rund eine Sekunde.
if ((string) ($_ENV['SKIP_TEST_DATABASE_PREPARE'] ?? $_SERVER['SKIP_TEST_DATABASE_PREPARE'] ?? '') !== '1') {
    $prepareOutput = [];
    $prepareExitCode = 1;
    exec(
        escapeshellarg(PHP_BINARY)
            . ' ' . escapeshellarg(__DIR__ . '/../bin/prepare_test_database.php')
            . ' ' . escapeshellarg($testDatabase)
            . ' 2>&1',
        $prepareOutput,
        $prepareExitCode
    );

    if ($prepareExitCode !== 0) {
        fwrite(STDERR, implode(PHP_EOL, $prepareOutput) . PHP_EOL);

        throw new RuntimeException(sprintf(
            'Die Testdatenbank "%s" konnte nicht vorbereitet werden.',
            $testDatabase
        ));
    }
}

// Ein zweiter Lauf auf derselben Datenbank soll abbrechen statt danebenzuschreiben.
Tests\Support\TestRunLock::holdForProcess(
    new PDO(
        sprintf(
            'mysql:host=%s;port=%s;charset=utf8mb4',
            (string) ($_ENV['DB_HOST'] ?? $_SERVER['DB_HOST'] ?? 'db'),
            (string) ($_ENV['DB_PORT'] ?? $_SERVER['DB_PORT'] ?? '3306')
        ),
        (string) ($_ENV['DB_USERNAME'] ?? $_SERVER['DB_USERNAME'] ?? 'db'),
        (string) ($_ENV['DB_PASSWORD'] ?? $_SERVER['DB_PASSWORD'] ?? 'db'),
        [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]
    ),
    $testDatabase
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

// Im parallelen Lauf verteilt paratest die Testklassen auf mehrere Prozesse. Die
// Reihenfolge der Test-Suites aus phpunit.xml gilt dann nur noch innerhalb eines
// Prozesses: tests/Guard/DatabaseLeakGuardTest landet in irgendeinem Worker und prüft
// dort auch nur dessen Datenbank zu dessen Zeitpunkt. Damit trotzdem jede
// hinterlassene Zeile auffällt, sieht jeder Worker am Ende seines Prozesses selbst nach.
if ($workerToken !== null) {
    register_shutdown_function(static function () use ($testDatabase): void {
        if (Tests\Support\DatabaseRowCountSnapshot::baseline() === null) {
            return;
        }

        try {
            $differences = Tests\Support\DatabaseRowCountSnapshot::differencesSinceBaseline();
        } catch (Throwable $comparisonError) {
            // Am Prozessende kann die Verbindung schon zu sein. Das ist kein Befund,
            // sondern eine fehlende Auskunft - und darf den Lauf nicht rot färben.
            fwrite(STDERR, sprintf(
                '%sZählstand von "%s" nicht vergleichbar: %s%s',
                PHP_EOL,
                $testDatabase,
                $comparisonError->getMessage(),
                PHP_EOL
            ));

            return;
        }

        if ($differences === []) {
            return;
        }

        fwrite(STDERR, sprintf(
            '%s%s: %s%s',
            PHP_EOL,
            $testDatabase,
            Tests\Support\DatabaseRowCountSnapshot::describeDifferences($differences),
            PHP_EOL
        ));

        // Ein exit() in der Abschlussfunktion überschreibt den Rückgabewert, den PHPUnit
        // gesetzt hat - sonst meldete der Worker den Lauf als erfolgreich.
        exit(1);
    });
}
