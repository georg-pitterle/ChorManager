<?php

declare(strict_types=1);

namespace Tests\Unit\Support;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use RuntimeException;
use Tests\Support\TestDatabaseGuard;

final class TestDatabaseGuardTest extends TestCase
{
    /**
     * @return array<string, array{0: string}>
     */
    public static function acceptedDatabaseNames(): array
    {
        return [
            'DDEV-Standard' => ['db'],
            'schlicht test' => ['test'],
            'testing' => ['testing'],
            'Suffix _test' => ['chormanager_test'],
        ];
    }

    #[DataProvider('acceptedDatabaseNames')]
    public function testAcceptsTestDatabaseNames(string $name): void
    {
        TestDatabaseGuard::assertTestDatabase($name);

        $this->assertTrue(TestDatabaseGuard::looksLikeTestDatabase($name));
    }

    public function testRejectsAForeignDatabaseName(): void
    {
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('chormanager');

        TestDatabaseGuard::assertTestDatabase('chormanager');
    }

    /**
     * Ohne Angabe greift in der Verbindung der Rückfall auf "db" - dann gibt es nichts
     * zu beanstanden.
     */
    public function testAcceptsAnEmptyDatabaseName(): void
    {
        $this->expectNotToPerformAssertions();

        TestDatabaseGuard::assertTestDatabase('');
    }

    /**
     * Ein Lauf gegen eine anders benannte Datenbank bleibt möglich, aber nur als
     * bewusste Handlung - nicht durch eine zufällig falsche .env.
     */
    public function testTheOverrideLetsAForeignNamePass(): void
    {
        $this->expectNotToPerformAssertions();

        TestDatabaseGuard::assertTestDatabase('chormanager', '1');
    }

    public function testAnUnrelatedOverrideValueDoesNotOpenTheGate(): void
    {
        $this->expectException(RuntimeException::class);

        TestDatabaseGuard::assertTestDatabase('chormanager', '0');
    }
}
