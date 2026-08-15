<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Util\Timezone;
use DI\ContainerBuilder;
use Illuminate\Database\Capsule\Manager as Capsule;
use PHPUnit\Framework\TestCase;
use Tests\Unit\Bootstrap;

/**
 * Die MySQL-Session lief mit einem fixen UTC-Offset. Nach einem Wechsel zwischen Sommer- und
 * Winterzeit las MySQL damit alle TIMESTAMP-Spalten um eine Stunde verschoben zurueck, weil der
 * Offset des aktuellen Laufs auf historische Werte angewendet wurde. Die Session muss deshalb die
 * benannte Zeitzone verwenden, solange die MySQL-Zeitzonentabellen geladen sind.
 */
final class DatabaseTimezoneFeatureTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        Bootstrap::setupTestDatabase();
    }

    /**
     * @return array<string, mixed>
     */
    private function databaseSettings(): array
    {
        $builder = new ContainerBuilder();
        $settings = require dirname(__DIR__, 2) . '/src/Settings.php';
        $settings($builder);

        return $builder->build()->get('settings')['db'];
    }

    public function testInitCommandPrefersTheNamedTimezoneAndFallsBackToTheOffset(): void
    {
        $command = Timezone::databaseTimezoneInitCommand();

        $this->assertStringContainsString('CONVERT_TZ', $command);
        $this->assertStringContainsString("'" . Timezone::resolveAppTimezone() . "'", $command);
        $this->assertStringContainsString("'" . Timezone::resolveDatabaseTimezoneOffset() . "'", $command);
    }

    public function testDatabaseSettingsCarryTheTimezoneInitCommand(): void
    {
        $settings = $this->databaseSettings();

        $this->assertArrayHasKey('options', $settings);
        $this->assertNotSame([], $settings['options']);
        $this->assertSame(Timezone::databaseConnectionOptions(), $settings['options']);
        $this->assertContains(Timezone::databaseTimezoneInitCommand(), $settings['options']);
        $this->assertArrayNotHasKey(
            'timezone',
            $settings,
            'Ein fixer Offset im Connection-Setup wuerde die benannte Zeitzone wieder ueberschreiben.'
        );
    }

    public function testConnectionSessionUsesTheNamedTimezone(): void
    {
        $capsule = new Capsule();
        $capsule->addConnection($this->databaseSettings(), 'timezone_probe');

        $connection = $capsule->getConnection('timezone_probe');
        $sessionTimezone = (string) $connection->selectOne('SELECT @@session.time_zone AS tz')->tz;
        $connection->disconnect();

        $this->assertSame(Timezone::resolveAppTimezone(), $sessionTimezone);
    }

    /**
     * Kernbeleg fuer den Fix: ein Zeitstempel aus der Gegenzeit (Winter bzw. Sommer) muss mit
     * dem damals gueltigen Offset zurueckkommen, nicht mit dem des aktuellen Laufs.
     */
    public function testTimestampsFromTheOtherDstPeriodKeepTheirOffset(): void
    {
        if (Timezone::resolveAppTimezone() !== 'Europe/Vienna') {
            $this->markTestSkipped('Die erwarteten Offsets gelten nur fuer Europe/Vienna.');
        }

        $capsule = new Capsule();
        $capsule->addConnection($this->databaseSettings(), 'timezone_dst_probe');

        $connection = $capsule->getConnection('timezone_dst_probe');
        $winter = (string) $connection
            ->selectOne("SELECT CONVERT_TZ('2026-01-15 12:00:00', 'UTC', @@session.time_zone) AS converted")
            ->converted;
        $summer = (string) $connection
            ->selectOne("SELECT CONVERT_TZ('2026-07-15 12:00:00', 'UTC', @@session.time_zone) AS converted")
            ->converted;
        $connection->disconnect();

        $this->assertSame('2026-01-15 13:00:00', $winter);
        $this->assertSame('2026-07-15 14:00:00', $summer);
    }
}
