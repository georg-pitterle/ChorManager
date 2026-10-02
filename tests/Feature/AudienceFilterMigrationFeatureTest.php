<?php

declare(strict_types=1);

namespace Tests\Feature;

use Illuminate\Database\Capsule\Manager as DB;
use PHPUnit\Framework\TestCase;
use Tests\Unit\Bootstrap;

/**
 * Umstellung der Freigaben auf Zielgruppen-Filter. Die Testdatenbank ist zu
 * Beginn des Laufs schon migriert; geprüft werden das Ergebnis-Schema, die
 * Zuordnung der alten Typen und die Lage der Prüfungen vor den destruktiven
 * Schritten. Den Umbau eines echten Bestands belegt der Handlauf im Plan.
 */
class AudienceFilterMigrationFeatureTest extends TestCase
{
    private function migration(): string
    {
        $content = file_get_contents(
            dirname(__DIR__, 2) . '/db/migrations/20261002090100_move_file_shares_to_audience_filters.php'
        );
        $this->assertIsString($content);

        return $content;
    }

    public function testOldColumnsAreGoneAndFilterIsRequired(): void
    {
        Bootstrap::setupTestDatabase();
        foreach (['file_folder_shares', 'file_shares'] as $table) {
            $columns = [];
            foreach (DB::select("SHOW COLUMNS FROM {$table}") as $column) {
                $columns[$column->Field] = $column->Null;
            }
            $this->assertArrayNotHasKey('target_type', $columns, $table);
            $this->assertArrayNotHasKey('reference_id', $columns, $table);
            $this->assertSame('NO', $columns['audience_filter_id'] ?? null, $table);
        }
    }

    public function testMappingCoversAllOldTargetTypes(): void
    {
        $content = $this->migration();
        foreach (
            ["'role' => 'role'", "'voice_group' => 'voice_group'", "'user' => 'user'", "'project_members' => 'project'"]
            as $pair
        ) {
            $this->assertStringContainsString($pair, $content);
        }
    }

    public function testAllMembersBecomesFilterWithoutConditionWhateverItsReference(): void
    {
        // Ohne Eintrag in CATEGORY_FOR_TYPE entsteht keine Bedingung - unabhängig von reference_id.
        $this->assertStringNotContainsString("'all_members' =>", $this->migration());
        $this->assertStringContainsString('if ($category !== null)', $this->migration());
    }

    public function testGuardsStandBeforeDestructiveSteps(): void
    {
        $content = $this->migration();
        $upStart = (int) strpos($content, 'function up()');
        $downStart = (int) strpos($content, 'function down()');
        $up = substr($content, $upStart, $downStart - $upStart);
        $down = substr($content, $downStart);

        $cases = [
            'up' => [$up, "removeColumn('target_type')"],
            'down' => [$down, "addColumn('target_type'"],
        ];
        foreach ($cases as $name => [$body, $step]) {
            $guard = strpos($body, 'throw new RuntimeException');
            $destructive = strpos($body, $step);
            // Ohne diese Prüfung gälte ein fehlendes throw (false) als "davor".
            $this->assertIsInt($guard, $name . ': Prüfung fehlt');
            $this->assertIsInt($destructive, $name . ': destruktiver Schritt nicht gefunden');
            $this->assertLessThan($destructive, $guard, $name . ': Prüfung steht nicht davor');
        }
    }
}
