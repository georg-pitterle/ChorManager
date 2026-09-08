<?php

declare(strict_types=1);

namespace Tests\Guard;

use PHPUnit\Framework\TestCase;
use Tests\Support\DatabaseRowCountSnapshot;
use Tests\Unit\Bootstrap;

/**
 * Läuft als eigene Test-Suite hinter allen anderen (siehe phpunit.xml) und vergleicht
 * den Zählstand der Datenbank mit dem, den tests/bootstrap.php vor dem ersten Test
 * aufgenommen hat.
 *
 * Anlass: Drei Notenarchiv-Tests und drei weitere legten Zeilen an, ohne sie
 * zurückzunehmen. Über Monate wuchsen daraus Hunderte Lieder namens "Test Song" und
 * ebenso viele Personen mit "@example.test"-Adressen. Sichtbar wurde das erst, als der
 * Bestand über einen Dump in einer echten Installation landete.
 */
final class DatabaseLeakGuardTest extends TestCase
{
    public function testTheSuiteLeavesNoRowsBehind(): void
    {
        Bootstrap::setupTestDatabase();

        if (DatabaseRowCountSnapshot::baseline() === null) {
            self::markTestSkipped(sprintf(
                'Kein Ausgangsstand aufgenommen, daher nichts zu vergleichen. Grund: %s',
                DatabaseRowCountSnapshot::baselineFailure() ?? 'unbekannt'
            ));
        }

        $differences = DatabaseRowCountSnapshot::differencesSinceBaseline();

        self::assertSame([], $differences, DatabaseRowCountSnapshot::describeDifferences($differences));
    }
}
