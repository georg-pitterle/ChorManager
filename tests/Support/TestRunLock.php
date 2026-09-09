<?php

declare(strict_types=1);

namespace Tests\Support;

use PDO;
use RuntimeException;

/**
 * Sperre für die Dauer eines Testlaufs, eine je Datenbank.
 *
 * Anlass: An diesem Repository arbeiten mehrere Sitzungen gleichzeitig, und jede startet
 * die Suite, wenn sie sie braucht. Trafen zwei Läufe auf denselben Bestand, sah das
 * hinterher aus wie ein undichter Test - der DatabaseLeakGuardTest verglich seinen
 * Ausgangsstand mit einem Zählstand, in den ein fremder Lauf hineingeschrieben hatte.
 * Schlimmer noch: MysqldumpRunnerFeatureTest sichert die gesamte Datenbank und spielt
 * sie zurück; parallel dazu entstand ein Dump mitten in fremden Schreibvorgängen.
 *
 * Deshalb bricht der zweite Lauf jetzt ab, statt still danebenzuschreiben. Die Sperre
 * hängt an der Verbindung: endet der Prozess - auch hart -, gibt die Datenbank sie
 * von selbst wieder frei.
 */
final class TestRunLock
{
    private const PREFIX = 'chormanager_testsuite_';

    /** MySQL nimmt Sperrnamen nur bis zu dieser Länge an. */
    private const MAX_NAME_LENGTH = 64;

    /**
     * Die Verbindung, an der die Sperre des Prozesses hängt. Ohne festgehaltene
     * Referenz räumt PHP sie nach dem Bootstrap weg, und die Datenbank gibt die
     * Sperre wieder frei - noch bevor der erste Test läuft.
     */
    private static ?PDO $processConnection = null;

    /**
     * @throws RuntimeException wenn bereits ein Lauf auf dieser Datenbank arbeitet.
     */
    public static function acquire(PDO $connection, string $database): void
    {
        $statement = $connection->prepare('SELECT GET_LOCK(?, 0)');
        $statement->execute([self::lockName($database)]);
        $acquired = $statement->fetchColumn();

        if ((int) $acquired === 1) {
            return;
        }

        throw new RuntimeException(sprintf(
            'Auf der Datenbank "%s" läuft bereits ein Testlauf.'
            . ' Zwei Läufe auf demselben Bestand überschreiben einander:'
            . ' der eine legt Zeilen an, während der andere seinen Zählstand vergleicht,'
            . ' und der Sicherungs-Test spielt zwischendurch einen Gesamtstand zurück.'
            . ' Erst den laufenden Test abwarten - oder mit einer eigenen Worker-Kennung'
            . ' starten, etwa TEST_TOKEN=2.',
            $database
        ));
    }

    /**
     * Nimmt die Sperre für die Dauer des Prozesses. Endet er - auch hart -, gibt die
     * Datenbank sie mit der Verbindung von selbst wieder frei.
     *
     * @throws RuntimeException wenn bereits ein Lauf auf dieser Datenbank arbeitet.
     */
    public static function holdForProcess(PDO $connection, string $database): void
    {
        self::acquire($connection, $database);

        self::$processConnection = $connection;
    }

    public static function releaseProcessLock(string $database): void
    {
        if (self::$processConnection === null) {
            return;
        }

        self::release(self::$processConnection, $database);
        self::$processConnection = null;
    }

    public static function release(PDO $connection, string $database): void
    {
        $statement = $connection->prepare('SELECT RELEASE_LOCK(?)');
        $statement->execute([self::lockName($database)]);
        $statement->fetchColumn();
    }

    public static function lockName(string $database): string
    {
        $name = self::PREFIX . $database;

        if (strlen($name) <= self::MAX_NAME_LENGTH) {
            return $name;
        }

        // Ein zu langer Name wird auf seine Prüfsumme verkürzt. Sie bleibt eindeutig
        // genug, damit zwei Datenbanken nicht auf dieselbe Sperre fallen.
        return self::PREFIX . substr(sha1($database), 0, self::MAX_NAME_LENGTH - strlen(self::PREFIX));
    }
}
