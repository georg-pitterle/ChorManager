<?php

declare(strict_types=1);

namespace Tests\Feature;

use Illuminate\Database\Capsule\Manager as DB;
use PHPUnit\Framework\TestCase;
use Tests\Unit\Bootstrap;

class EventAudienceFilterMigrationFeatureTest extends TestCase
{
    private function migration(): string
    {
        return (string) file_get_contents(
            dirname(__DIR__, 2) . '/db/migrations/20261004090200_move_event_audience_to_filters.php'
        );
    }

    public function testSourceTableIsGone(): void
    {
        Bootstrap::setupTestDatabase();
        $this->assertSame([], DB::select("SHOW TABLES LIKE 'event_audience_sources'"));
    }

    public function testMappingCoversAllOldSourceTypes(): void
    {
        foreach (
            ["'role' => 'role'", "'voice_group' => 'voice_group'", "'user' => 'user'", "'project_members' => 'project'"]
            as $pair
        ) {
            $this->assertStringContainsString($pair, $this->migration());
        }
    }

    public function testGuardStandsBeforeDroppingTheTable(): void
    {
        $content = $this->migration();
        $guard = strpos($content, 'throw new RuntimeException');
        $drop = strpos($content, "table('event_audience_sources')->drop()");
        $this->assertNotFalse($guard);
        $this->assertNotFalse($drop);
        $this->assertLessThan($drop, $guard);
    }

    /**
     * Ein Termin, dessen Quellen alle keinen auswertbaren Typ trugen, galt schon
     * vorher für niemanden. Er bekommt keinen Filter - und darf die Prüfung
     * nicht als "unvollständig übertragen" abbrechen lassen.
     */
    public function testEventsWithOnlyUnevaluableSourcesDoNotBlockTheMigration(): void
    {
        $content = $this->migration();
        $up = substr($content, (int) strpos($content, 'function up()'), (int) strpos($content, 'function down()'));
        $this->assertStringContainsString('$unevaluable', $up);
        $this->assertMatchesRegularExpression('/\$withoutFilter\s*>\s*\$unevaluable/', $up);
    }

    /**
     * Ein Termin ohne Filter trifft niemanden; ohne Quelle hieße er im alten
     * Modell "alle". Der Rückbau würde ihn also öffnen und muss verweigern.
     */
    public function testDownRefusesEventsWithoutAnyFilter(): void
    {
        $content = $this->migration();
        $down = substr($content, (int) strpos($content, 'function down()'));
        $guard = strpos($down, '$withoutFilter');
        $this->assertIsInt($guard);
        $this->assertLessThan((int) strpos($down, "table('event_audience_sources')"), $guard);
    }

    public function testDownRefusesCombinedFilters(): void
    {
        $this->assertMatchesRegularExpression('/function down\(\).*?COUNT\(\*\).*?> 1.*?throw new RuntimeException/s', $this->migration());
    }
}
