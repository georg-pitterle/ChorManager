<?php

declare(strict_types=1);

namespace Tests\Feature;

use Illuminate\Database\Capsule\Manager as DB;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Tests\Unit\Bootstrap;

/**
 * Spalten, die die Anwendung auf jedem Schreibweg setzt, dürfen in der
 * Datenbank nicht nullbar sein.
 *
 * Phinx legt eine Spalte ohne `'null' => false` nullbar an. Dadurch sind
 * reihenweise Pflichtfelder nullbar geblieben, obwohl kein Schreibweg sie je
 * leer lässt - und bei vier von ihnen hängt ein `UNIQUE`-Index daran, der mit
 * einem Nullwert nicht mehr greift: MySQL vergleicht `NULL` nie als gleich,
 * zwei Zeilen mit Nullwert verletzen den Index also nicht.
 *
 * Konkret stand damit offen:
 * - `event_registrations (event_id, user_id)` - zwei Anmeldungen derselben
 *   Person zum selben Termin.
 * - `budget_categories (fiscal_year_start, finance_group_id, type)` - zwei
 *   gleiche Haushaltskategorien.
 * - `newsletter_recipient_sources.source_type` - die Spiegeltabelle
 *   `newsletter_template_recipient_sources` hat dieselbe Spalte seit
 *   20261004090300 als `NOT NULL`; dass die eine nullbar blieb, war ein
 *   Versehen in derselben Migration.
 * - `calendar_subscription_tokens.token_hash` und die vier Spalten von
 *   `invitation_tokens` - Zugangsdaten ohne ihren Wert sind wertlos.
 *
 * Die vier Spalten der Mail-Strecke und des Finanzjournals kamen mit
 * 20261019130000 dazu. Sie stehen in über Jahre gewachsenen Tabellen und waren
 * deshalb zuerst aussen vor, bis geprüft war, ob dort Nullwerte liegen.
 */
class RequiredColumnsAreNotNullableFeatureTest extends TestCase
{
    /**
     * @return array<string, array{0: string, 1: string}>
     */
    public static function requiredColumnProvider(): array
    {
        $columns = [
            'budget_categories' => ['finance_group_id'],
            'calendar_subscription_tokens' => ['token_hash'],
            'event_registrations' => ['event_id', 'user_id', 'status'],
            'finance_accounts' => ['name', 'type'],
            'finance_revisions' => ['action'],
            'invitation_tokens' => ['user_id', 'selector', 'token_hash', 'expires_at'],
            'mail_delivery_events' => ['source_channel'],
            'mail_queue' => ['mail_type', 'delivery_status'],
            'newsletter_recipient_sources' => ['source_type'],
        ];

        $cases = [];
        foreach ($columns as $table => $names) {
            foreach ($names as $name) {
                $cases[$table . '.' . $name] = [$table, $name];
            }
        }

        return $cases;
    }

    #[DataProvider('requiredColumnProvider')]
    public function testRequiredColumnIsNotNullable(string $table, string $column): void
    {
        Bootstrap::setupTestDatabase();

        $found = DB::selectOne(
            'SELECT IS_NULLABLE FROM information_schema.COLUMNS
             WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND COLUMN_NAME = ?',
            [$table, $column]
        );

        $this->assertNotNull($found, $table . '.' . $column . ' fehlt.');
        $this->assertSame(
            'NO',
            (string) $found->IS_NULLABLE,
            $table . '.' . $column . ' ist nullbar, obwohl jeder Schreibweg einen Wert setzt.'
        );
    }

    /**
     * Die Spiegeltabellen der Newsletter-Empfänger dürfen nicht wieder
     * auseinanderlaufen.
     */
    public function testBothRecipientSourceTablesAgreeOnSourceType(): void
    {
        Bootstrap::setupTestDatabase();

        $tables = ['newsletter_recipient_sources', 'newsletter_template_recipient_sources'];
        $definitions = [];
        foreach ($tables as $table) {
            $column = DB::selectOne(
                'SELECT COLUMN_TYPE, IS_NULLABLE FROM information_schema.COLUMNS
                 WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND COLUMN_NAME = ?',
                [$table, 'source_type']
            );
            $definitions[$table] = [(string) $column->COLUMN_TYPE, (string) $column->IS_NULLABLE];
        }

        $this->assertSame(
            $definitions[$tables[0]],
            $definitions[$tables[1]],
            'source_type ist in den beiden Spiegeltabellen unterschiedlich definiert.'
        );
    }

    /**
     * @return array<string, array{0: string}>
     */
    public static function narrowingMigrationProvider(): array
    {
        return [
            '20261019120000' => ['20261019120000_require_values_in_mandatory_columns.php'],
            '20261019130000' => ['20261019130000_require_values_in_grown_columns.php'],
        ];
    }

    /**
     * Jede Verengung braucht ihre Zählabfrage davor - und zwar alle vor der
     * ersten Änderung, damit ein Bestand mit Nullwerten den Lauf abbricht,
     * bevor irgendeine Spalte angefasst wurde.
     */
    #[DataProvider('narrowingMigrationProvider')]
    public function testEveryGuardStandsBeforeTheFirstNarrowing(string $migration): void
    {
        $content = (string) file_get_contents(dirname(__DIR__, 2) . '/db/migrations/' . $migration);

        $upStart = (int) strpos($content, 'function up()');
        $up = substr($content, $upStart, (int) strpos($content, 'function down()') - $upStart);

        $guard = strpos($up, 'throw new RuntimeException');
        $narrowing = strpos($up, "'null' => false");

        $this->assertIsInt($guard, 'Prüfung fehlt: ' . $migration);
        $this->assertIsInt($narrowing, 'Verengung fehlt: ' . $migration);
        $this->assertLessThan($narrowing, $guard, 'Prüfung steht nicht vor der Verengung: ' . $migration);
    }
}
