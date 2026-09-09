<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Services\MysqldumpRunner;
use PDO;
use PHPUnit\Framework\TestCase;

/**
 * Prüft Sicherung und Rückspielung des Dump-Läufers an einer eigenen, winzigen
 * Datenbank.
 *
 * Vorher lief beides gegen die Datenbank des Testlaufs. Das war aus zwei Gründen
 * schlecht: der Rundlauf dauerte allein neuneinhalb Sekunden, weil er alle 54 Tabellen
 * sicherte und zurückspielte, und er konnte den Bestand des Laufs auf einen Stand von
 * vorhin zurücksetzen - ein abgebrochener Lauf hinterließ dabei sogar fehlende
 * Tabellen. Was hier geprüft wird, ist die Mechanik des Läufers, nicht das Schema der
 * Anwendung; dafür genügt eine Tabelle.
 */
final class MysqldumpRunnerFeatureTest extends TestCase
{
    private static ?string $probeDatabase = null;

    private PDO $connection;
    private string $tmpFile;

    /**
     * @return array{host: string, port: string, username: string, password: string}
     */
    private static function connectionSettings(): array
    {
        return [
            'host' => (string) ($_ENV['DB_HOST'] ?? $_SERVER['DB_HOST'] ?? 'db'),
            'port' => (string) ($_ENV['DB_PORT'] ?? $_SERVER['DB_PORT'] ?? '3306'),
            'username' => (string) ($_ENV['DB_USERNAME'] ?? $_SERVER['DB_USERNAME'] ?? 'db'),
            'password' => (string) ($_ENV['DB_PASSWORD'] ?? $_SERVER['DB_PASSWORD'] ?? 'db'),
        ];
    }

    private static function serverConnection(): PDO
    {
        $settings = self::connectionSettings();

        return new PDO(
            sprintf('mysql:host=%s;port=%s;charset=utf8mb4', $settings['host'], $settings['port']),
            $settings['username'],
            $settings['password'],
            [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]
        );
    }

    /**
     * Der Name hängt an dem der Testdatenbank: parallele Testprozesse arbeiten sonst
     * auf derselben Sonde und spielen einander den Stand zurück.
     */
    private static function probeDatabaseName(): string
    {
        $runDatabase = (string) ($_ENV['DB_DATABASE'] ?? $_SERVER['DB_DATABASE'] ?? 'db_test');

        return $runDatabase . '_dumpprobe';
    }

    public static function setUpBeforeClass(): void
    {
        parent::setUpBeforeClass();

        self::$probeDatabase = self::probeDatabaseName();

        $connection = self::serverConnection();
        $connection->exec(sprintf('DROP DATABASE IF EXISTS `%s`', self::$probeDatabase));
        $connection->exec(sprintf(
            'CREATE DATABASE `%s` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci',
            self::$probeDatabase
        ));
    }

    public static function tearDownAfterClass(): void
    {
        if (self::$probeDatabase !== null) {
            self::serverConnection()->exec(sprintf('DROP DATABASE IF EXISTS `%s`', self::$probeDatabase));
            self::$probeDatabase = null;
        }

        parent::tearDownAfterClass();
    }

    protected function setUp(): void
    {
        parent::setUp();

        $settings = self::connectionSettings();
        $this->connection = new PDO(
            sprintf(
                'mysql:host=%s;port=%s;dbname=%s;charset=utf8mb4',
                $settings['host'],
                $settings['port'],
                (string) self::$probeDatabase
            ),
            $settings['username'],
            $settings['password'],
            [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]
        );

        $this->connection->exec(
            'CREATE TABLE IF NOT EXISTS backup_runner_probe (id INT PRIMARY KEY, marker VARCHAR(64))'
        );
        $this->connection->exec('TRUNCATE TABLE backup_runner_probe');
        $this->connection->exec("INSERT INTO backup_runner_probe (id, marker) VALUES (1, 'probe-before-restore')");

        $this->tmpFile = sys_get_temp_dir() . '/chormanager_mysqldump_test_' . bin2hex(random_bytes(4)) . '.sql.gz';
    }

    protected function tearDown(): void
    {
        if (file_exists($this->tmpFile)) {
            unlink($this->tmpFile);
        }

        $this->connection->exec('DROP TABLE IF EXISTS backup_runner_probe');

        parent::tearDown();
    }

    private function makeRunner(): MysqldumpRunner
    {
        $settings = self::connectionSettings();

        return new MysqldumpRunner(
            $settings['host'],
            $settings['port'],
            (string) self::$probeDatabase,
            $settings['username'],
            $settings['password']
        );
    }

    /**
     * Ein abgebrochener Schreibvorgang - etwa weil der Datenträger voll ist - darf
     * nicht als fertiges Backup durchgehen. Die Prüfsumme entsteht erst danach und
     * würde die abgeschnittene Datei als unversehrt ausweisen; beim Einspielen käme
     * nur ein Teil der Datenbank zurück, ohne dass irgendwo ein Fehler stünde.
     *
     * `/dev/full` verhält sich wie ein voller Datenträger: Schreiben schlägt mit
     * ENOSPC fehl, Öffnen gelingt.
     */
    public function testDumpFailsWhenTheDestinationCannotTakeTheData(): void
    {
        if (!is_writable('/dev/full')) {
            $this->markTestSkipped('/dev/full steht in dieser Umgebung nicht zur Verfügung.');
        }

        $runner = $this->makeRunner();

        $this->expectException(\RuntimeException::class);
        $runner->dump('/dev/full', false);
    }

    public function testDumpAndRestoreRoundTripsProbeTable(): void
    {
        $runner = $this->makeRunner();

        $runner->dump($this->tmpFile, true);

        $this->assertFileExists($this->tmpFile);
        $this->assertGreaterThan(0, filesize($this->tmpFile));

        $this->connection->exec("UPDATE backup_runner_probe SET marker = 'probe-overwritten' WHERE id = 1");

        $runner->restore($this->tmpFile, true);

        $marker = $this->connection
            ->query('SELECT marker FROM backup_runner_probe WHERE id = 1')
            ->fetchColumn();

        $this->assertSame('probe-before-restore', $marker);
    }
}
