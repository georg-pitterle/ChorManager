<?php

/**
 * Legt die Datenbank eines Testlaufs an und bringt sie auf den Stand der Migrationen.
 *
 * Die Suite arbeitet nicht mehr auf der Entwicklungsdatenbank. Sie schrieb dort sonst in
 * denselben Bestand, den die Entwicklung im Browser benutzt, und zwei gleichzeitige
 * Läufe schrieben in denselben - sichtbar als sporadisch roter DatabaseLeakGuardTest.
 * Welche Datenbank ein Lauf bekommt, entscheidet Tests\Support\TestDatabaseName.
 *
 * Aufruf:
 *   php bin/prepare_test_database.php            Datenbank des aktuellen Laufs vorbereiten
 *   TEST_TOKEN=2 php bin/prepare_test_database.php   dieselbe für einen zweiten Prozess
 *   php bin/prepare_test_database.php db_test_1  genau diese Datenbank vorbereiten
 *
 * Den Namen mitzugeben ist der Weg aus tests/bootstrap.php: paratest setzt TEST_TOKEN
 * nur in seinem eigenen Prozess, der hier gestartete Kindprozess sähe die Kennung nicht
 * und würde einen anderen Namen ableiten.
 *
 * Der Aufruf ist wiederholbar: eine vorhandene Datenbank bleibt stehen, `phinx migrate`
 * holt nur nach, was fehlt.
 */

declare(strict_types=1);

require __DIR__ . '/../vendor/autoload.php';

use Dotenv\Dotenv;
use Tests\Support\TestDatabaseGuard;
use Tests\Support\TestDatabaseName;

$projectRoot = dirname(__DIR__);

if (file_exists($projectRoot . '/.env')) {
    Dotenv::createImmutable($projectRoot)->safeLoad();
}

$requestedName = $argv[1] ?? null;

if ($requestedName !== null) {
    // Angelegt wird nur, was auch nach einer Testdatenbank aussieht - der Name landet
    // in "CREATE DATABASE".
    if (!TestDatabaseGuard::looksLikeTestDatabase($requestedName)) {
        fwrite(STDERR, sprintf('"%s" ist kein Name einer Testdatenbank.%s', $requestedName, PHP_EOL));

        exit(1);
    }

    $database = $requestedName;
} else {
    $configuredName = (string) ($_ENV['DB_DATABASE'] ?? $_SERVER['DB_DATABASE'] ?? '');
    $workerToken = $_ENV['TEST_TOKEN'] ?? $_SERVER['TEST_TOKEN'] ?? null;
    $database = TestDatabaseName::forRun($configuredName, $workerToken === null ? null : (string) $workerToken);
}

$host = (string) ($_ENV['DB_HOST'] ?? $_SERVER['DB_HOST'] ?? 'db');
$port = (string) ($_ENV['DB_PORT'] ?? $_SERVER['DB_PORT'] ?? '3306');
$username = (string) ($_ENV['DB_USERNAME'] ?? $_SERVER['DB_USERNAME'] ?? 'db');
$password = (string) ($_ENV['DB_PASSWORD'] ?? $_SERVER['DB_PASSWORD'] ?? 'db');

$connection = new PDO(
    sprintf('mysql:host=%s;port=%s;charset=utf8mb4', $host, $port),
    $username,
    $password,
    [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]
);

// Der Name kommt aus TestDatabaseName und ist dort auf einen schlichten Bezeichner
// geprüft; als Platzhalter lässt MySQL ihn an dieser Stelle nicht zu.
$connection->exec(sprintf(
    'CREATE DATABASE IF NOT EXISTS `%s` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci',
    $database
));

// Phinx liest den Namen aus der Umgebung (siehe phinx.php), nicht aus dieser Datei.
putenv('DB_DATABASE=' . $database);
$_ENV['DB_DATABASE'] = $database;
$_SERVER['DB_DATABASE'] = $database;

$command = escapeshellarg(PHP_BINARY)
    . ' ' . escapeshellarg($projectRoot . '/vendor/bin/phinx')
    . ' migrate -e development';

$output = [];
$exitCode = 1;
exec($command . ' 2>&1', $output, $exitCode);

if ($exitCode !== 0) {
    fwrite(STDERR, sprintf(
        'Die Migrationen der Testdatenbank "%s" sind fehlgeschlagen:%s%s%s',
        $database,
        PHP_EOL,
        implode(PHP_EOL, $output),
        PHP_EOL
    ));

    exit(1);
}

fwrite(STDOUT, sprintf('Testdatenbank "%s" ist bereit.%s', $database, PHP_EOL));
