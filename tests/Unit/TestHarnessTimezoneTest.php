<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Util\Timezone;
use Illuminate\Database\Capsule\Manager as Capsule;
use PHPUnit\Framework\TestCase;

/**
 * Der Testlauf muss in derselben Zeitzone rechnen wie der Web-Einstieg
 * (public/index.php) und die CLI-Skripte (bin/bootstrap_cli.php).
 *
 * Lief PHPUnit in UTC, während Seed und Oberfläche in der App-Zeitzone
 * schreiben, verglichen Tests ihre frisch erzeugten Zeitstempel gegen
 * bestehende Daten, die um den Zonenversatz in der Zukunft lagen. Aufgefallen
 * ist das an MailQueueAdminServiceFeatureTest: nach einem Seed-Lauf füllten
 * die Seed-Zeilen die erste Seite der Warteschlange komplett, der im Test
 * angelegte Eintrag rutschte dahinter und galt als nicht gefunden.
 */
final class TestHarnessTimezoneTest extends TestCase
{
    public function testPhpRunsInTheApplicationTimezone(): void
    {
        $this->assertSame(
            Timezone::resolveAppTimezone(),
            date_default_timezone_get(),
            'tests/bootstrap.php muss dieselbe Zeitzone setzen wie public/index.php.'
        );
    }

    public function testDatabaseSessionSharesTheApplicationOffset(): void
    {
        Bootstrap::setupTestDatabase();

        $row = Capsule::connection()->selectOne(
            'SELECT TIMESTAMPDIFF(MINUTE, UTC_TIMESTAMP(), NOW()) AS offset_minutes'
        );

        $expectedOffset = (new \DateTimeImmutable('now', new \DateTimeZone(Timezone::resolveAppTimezone())))
            ->getOffset() / 60;

        $this->assertSame(
            (int) $expectedOffset,
            (int) $row->offset_minutes,
            'Die Testverbindung muss dieselben Zeitzonen-Optionen bekommen wie die Anwendung.'
        );
    }
}
