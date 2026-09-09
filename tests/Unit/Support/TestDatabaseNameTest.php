<?php

declare(strict_types=1);

namespace Tests\Unit\Support;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use RuntimeException;
use Tests\Support\TestDatabaseGuard;
use Tests\Support\TestDatabaseName;

/**
 * Der Name der Datenbank, gegen die ein Testlauf arbeitet, wird abgeleitet und nicht
 * aus der .env übernommen. Sonst schreibt die Suite in denselben Bestand, den die
 * Entwicklung im Browser benutzt - und zwei gleichzeitige Läufe in denselben.
 */
final class TestDatabaseNameTest extends TestCase
{
    /**
     * @return array<string, array{0: string, 1: string|null, 2: string}>
     */
    public static function derivedNames(): array
    {
        return [
            'DDEV-Standard ohne Worker' => ['db', null, 'db_test'],
            'DDEV-Standard mit Worker' => ['db', '2', 'db_test_2'],
            'bereits ein Testname' => ['db_test', null, 'db_test'],
            'Testname mit Worker' => ['db_test', '3', 'db_test_3'],
            'leerer Name fällt auf db zurück' => ['', null, 'db_test'],
            'leerer Worker zählt wie keiner' => ['db', '  ', 'db_test'],
            'eigener Bestandsname' => ['chormanager', null, 'chormanager_test'],
        ];
    }

    #[DataProvider('derivedNames')]
    public function testDerivesTheDatabaseNameOfARun(string $configured, ?string $token, string $expected): void
    {
        $this->assertSame($expected, TestDatabaseName::forRun($configured, $token));
    }

    /**
     * Der abgeleitete Name landet in "CREATE DATABASE" - er darf deshalb nichts
     * enthalten, was dort etwas anderes bedeutet als einen Bezeichner.
     */
    public function testRejectsAWorkerTokenThatIsNotAPlainIdentifier(): void
    {
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('Worker');

        TestDatabaseName::forRun('db', '1`; DROP DATABASE `db');
    }

    /**
     * Der Wächter aus TestDatabaseGuard muss jeden abgeleiteten Namen durchlassen,
     * sonst bricht der Lauf an der eigenen Absicherung ab.
     */
    #[DataProvider('derivedNames')]
    public function testEveryDerivedNamePassesTheDatabaseGuard(
        string $configured,
        ?string $token,
        string $expected
    ): void {
        $this->assertSame($expected, TestDatabaseName::forRun($configured, $token));
        $this->assertTrue(TestDatabaseGuard::looksLikeTestDatabase($expected));
    }
}
