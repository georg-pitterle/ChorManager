<?php

declare(strict_types=1);

namespace Tests\Feature;

use Illuminate\Database\Capsule\Manager as DB;
use PHPUnit\Framework\TestCase;
use Tests\Unit\Bootstrap;

/**
 * Umstellung der Newsletter- und Vorlagen-Empfänger auf Zielgruppen-Filter.
 * Geprüft werden das Ergebnis-Schema, die Zuordnung der alten Typen und die
 * Lage der Prüfungen vor den destruktiven Schritten.
 */
class NewsletterAudienceFilterMigrationFeatureTest extends TestCase
{
    private function migration(): string
    {
        return (string) file_get_contents(
            dirname(__DIR__, 2) . '/db/migrations/20261004090300_move_newsletter_audience_to_filters.php'
        );
    }

    public function testSourceTablesKeepOnlyEventAttendees(): void
    {
        Bootstrap::setupTestDatabase();
        foreach (['newsletter_recipient_sources', 'newsletter_template_recipient_sources'] as $table) {
            $column = DB::selectOne("SHOW COLUMNS FROM {$table} LIKE 'source_type'");
            $this->assertSame("enum('event_attendees')", (string) $column->Type, $table);
        }
    }

    public function testMappingCoversTheTransferredSourceTypes(): void
    {
        foreach (["'project_members' => 'project'", "'role' => 'role'", "'user' => 'user'"] as $pair) {
            $this->assertStringContainsString($pair, $this->migration());
        }
    }

    public function testGuardStandsBeforeTheDestructiveSteps(): void
    {
        $content = $this->migration();
        $upStart = (int) strpos($content, 'function up()');
        $up = substr($content, $upStart, (int) strpos($content, 'function down()') - $upStart);
        $guard = strpos($up, 'throw new RuntimeException');
        $this->assertIsInt($guard, 'Prüfung fehlt');
        foreach (["DELETE FROM {\$table}", "changeColumn('source_type'"] as $step) {
            $position = strpos($up, $step);
            $this->assertIsInt($position, $step . ' nicht gefunden');
            $this->assertLessThan($position, $guard, $step);
        }
    }

    public function testDownRefusesWhatTheOldModelCannotHold(): void
    {
        $content = $this->migration();
        $down = substr($content, (int) strpos($content, 'function down()'));
        $guard = strpos($down, 'throw new RuntimeException');
        $this->assertIsInt($guard);
        $this->assertLessThan((int) strpos($down, "changeColumn('source_type'"), $guard);
        $this->assertStringContainsString("'voice_group', 'sub_voice'", $down);
        $this->assertStringContainsString('<> 1', $down);
    }
}
