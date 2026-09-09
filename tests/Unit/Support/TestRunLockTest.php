<?php

declare(strict_types=1);

namespace Tests\Unit\Support;

use PDO;
use PHPUnit\Framework\TestCase;
use RuntimeException;
use Tests\Support\TestRunLock;

/**
 * Zwei Testläufe gleichzeitig auf derselben Datenbank zerlegen einander: der eine legt
 * Zeilen an, während der andere seinen Zählstand vergleicht, und MysqldumpRunnerFeatureTest
 * spielt zwischendurch einen Gesamtstand zurück. Der zweite Lauf soll deshalb sofort
 * abbrechen statt mitzuschreiben.
 */
final class TestRunLockTest extends TestCase
{
    /** @var list<PDO> */
    private array $connections = [];

    protected function tearDown(): void
    {
        foreach ($this->connections as $connection) {
            TestRunLock::release($connection, 'db_test_lockprobe');
        }

        $this->connections = [];

        parent::tearDown();
    }

    private function connect(): PDO
    {
        $host = (string) ($_ENV['DB_HOST'] ?? $_SERVER['DB_HOST'] ?? 'db');
        $user = (string) ($_ENV['DB_USERNAME'] ?? $_SERVER['DB_USERNAME'] ?? 'db');
        $password = (string) ($_ENV['DB_PASSWORD'] ?? $_SERVER['DB_PASSWORD'] ?? 'db');

        $connection = new PDO(
            sprintf('mysql:host=%s;charset=utf8mb4', $host),
            $user,
            $password,
            [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]
        );

        $this->connections[] = $connection;

        return $connection;
    }

    public function testTheFirstRunGetsTheLock(): void
    {
        $this->expectNotToPerformAssertions();

        TestRunLock::acquire($this->connect(), 'db_test_lockprobe');
    }

    public function testASecondRunOnTheSameDatabaseIsTurnedAway(): void
    {
        TestRunLock::acquire($this->connect(), 'db_test_lockprobe');

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('db_test_lockprobe');

        TestRunLock::acquire($this->connect(), 'db_test_lockprobe');
    }

    /**
     * Der Sinn der Worker-Datenbanken: parallele Prozesse arbeiten auf getrennten
     * Beständen und dürfen sich deshalb nicht gegenseitig aussperren.
     */
    public function testRunsOnDifferentDatabasesDoNotBlockEachOther(): void
    {
        $this->expectNotToPerformAssertions();

        $first = $this->connect();
        TestRunLock::acquire($first, 'db_test_lockprobe');

        $second = $this->connect();
        TestRunLock::acquire($second, 'db_test_lockprobe_2');
        TestRunLock::release($second, 'db_test_lockprobe_2');
    }

    /**
     * MySQL nimmt nur Sperrnamen bis 64 Zeichen an. Ein längerer Datenbankname darf
     * den Lauf nicht an der eigenen Absicherung scheitern lassen.
     */
    public function testTheLockNameStaysWithinTheLimitOfTheDatabase(): void
    {
        $name = TestRunLock::lockName(str_repeat('a', 120) . '_test');

        $this->assertLessThanOrEqual(64, strlen($name));
    }

    /**
     * Der Bootstrap nimmt die Sperre für den ganzen Prozess. Ohne festgehaltene
     * Referenz schließt PHP die Verbindung nach dem Bootstrap wieder - und mit ihr
     * fällt die Sperre, noch bevor der erste Test läuft.
     */
    public function testTheProcessLockOutlivesTheLocalReference(): void
    {
        (function (): void {
            TestRunLock::holdForProcess($this->connect(), 'db_test_lockprobe');
        })();

        gc_collect_cycles();

        try {
            $this->expectException(RuntimeException::class);

            TestRunLock::acquire($this->connect(), 'db_test_lockprobe');
        } finally {
            TestRunLock::releaseProcessLock('db_test_lockprobe');
        }
    }

    public function testDifferentDatabasesGetDifferentLockNames(): void
    {
        $this->assertNotSame(
            TestRunLock::lockName('db_test'),
            TestRunLock::lockName('db_test_2')
        );
    }
}
