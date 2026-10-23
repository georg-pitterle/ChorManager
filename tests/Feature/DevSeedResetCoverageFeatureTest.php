<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Services\DevSeedService;
use Illuminate\Database\Capsule\Manager as Capsule;
use PHPUnit\Framework\TestCase;
use ReflectionClass;
use ReflectionMethod;
use Tests\Unit\Bootstrap;

/**
 * `dev_seed.php --mode=reset-and-seed` soll einen Bestand hinterlassen, in dem nur
 * noch Seed-Daten stehen. Die zu leerenden Tabellen standen vorher als feste Liste
 * im Service; `invitation_tokens` fehlte darin, und jede neue Tabelle hätte genauso
 * gefehlt. Nach dem Einspielen eines Produktiv-Backups blieben so echte
 * Einladungslinks im Dev-Stand liegen.
 *
 * Geprüft wird die Auswahl der Tabellen, nicht das Leeren selbst: TRUNCATE lässt
 * sich nicht zurückrollen und nähme der Test-Datenbank ihre Grunddaten.
 */
final class DevSeedResetCoverageFeatureTest extends TestCase
{
    private const PROBE_TABLE = 'dev_seed_reset_probe';

    protected function setUp(): void
    {
        parent::setUp();
        Bootstrap::setupTestDatabase();
        Capsule::connection()->statement('DROP TABLE IF EXISTS ' . self::PROBE_TABLE);
    }

    protected function tearDown(): void
    {
        Capsule::connection()->statement('DROP TABLE IF EXISTS ' . self::PROBE_TABLE);
        parent::tearDown();
    }

    public function testResetCoversInvitationTokens(): void
    {
        $this->assertContains('invitation_tokens', $this->resetTables());
    }

    public function testResetCoversATableTheSeedHasNeverHeardOf(): void
    {
        Capsule::connection()->statement('CREATE TABLE ' . self::PROBE_TABLE . ' (id INT PRIMARY KEY)');

        $this->assertContains(self::PROBE_TABLE, $this->resetTables());
    }

    public function testResetCoversEveryTableExceptTheMigrationLog(): void
    {
        $schemaTables = array_map(
            static fn(object $row): string => (string) $row->name,
            Capsule::connection()->select(
                "SELECT table_name AS name FROM information_schema.tables
                 WHERE table_schema = DATABASE() AND table_type = 'BASE TABLE'"
            )
        );

        $resetTables = $this->resetTables();

        $this->assertNotContains('phinxlog', $resetTables);
        $this->assertEqualsCanonicalizing(array_values(array_diff($schemaTables, ['phinxlog'])), $resetTables);
    }

    /**
     * @return list<string>
     */
    private function resetTables(): array
    {
        $seed = (new ReflectionClass(DevSeedService::class))->newInstanceWithoutConstructor();

        return (new ReflectionMethod(DevSeedService::class, 'resetTables'))->invoke($seed);
    }
}
