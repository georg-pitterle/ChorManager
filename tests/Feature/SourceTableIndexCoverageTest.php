<?php

declare(strict_types=1);

namespace Tests\Feature;

use Illuminate\Database\Capsule\Manager as Capsule;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Tests\Unit\Bootstrap;

/**
 * Die drei Quellen-Tabellen tragen seit 20260901120000 nur noch den eindeutigen
 * Dreier-Index auf (Elternspalte, source_type, reference_id).
 *
 * Geprüft wird beides: dass der Einzelindex weg ist - er kostete Schreiblast,
 * ohne eine Abfrage zu beantworten, die der Dreier nicht auch beantwortet - und
 * dass der Fremdschlüssel weiterhin gedeckt ist. MySQL verlangt dafür einen
 * Index, in dem die Elternspalte an erster Stelle steht; fehlt der, lässt sich
 * die Zeile nicht mehr schreiben.
 */
final class SourceTableIndexCoverageTest extends TestCase
{
    /**
     * @return array<string, array{0: string, 1: string, 2: string}>
     */
    public static function sourceTableProvider(): array
    {
        return [
            'Termin-Zielgruppen' => [
                'event_audience_sources',
                'event_id',
                'uq_event_audience_source',
            ],
            'Newsletter-Empfänger' => [
                'newsletter_recipient_sources',
                'newsletter_id',
                'uq_newsletter_recipient_source',
            ],
            'Vorlagen-Empfänger' => [
                'newsletter_template_recipient_sources',
                'template_id',
                'uq_newsletter_template_recipient_source',
            ],
        ];
    }

    protected function setUp(): void
    {
        Bootstrap::setupTestDatabase();
    }

    #[DataProvider('sourceTableProvider')]
    public function testRedundantSingleColumnIndexIsGone(
        string $table,
        string $parentColumn,
        string $coveringIndex
    ): void {
        $this->assertSame(
            [],
            $this->indexColumns($table, $parentColumn),
            sprintf(
                '%s: Der Einzelindex %s wird vom Dreier-Index abgedeckt und darf nicht zurückkehren.',
                $table,
                $parentColumn
            )
        );

        $this->assertSame(
            [$parentColumn, 'source_type', 'reference_id'],
            $this->indexColumns($table, $coveringIndex),
            sprintf('%s: Der deckende Index muss %s an erster Stelle führen.', $table, $parentColumn)
        );
    }

    /**
     * Der Fremdschlüssel bleibt bestehen - und mit ihm die Pflicht, ihn zu decken.
     */
    #[DataProvider('sourceTableProvider')]
    public function testForeignKeyOnParentColumnStillExists(
        string $table,
        string $parentColumn,
        string $coveringIndex
    ): void {
        $rows = Capsule::connection()->select(sprintf(
            "SELECT CONSTRAINT_NAME
             FROM information_schema.KEY_COLUMN_USAGE
             WHERE TABLE_SCHEMA = DATABASE()
               AND TABLE_NAME = '%s'
               AND COLUMN_NAME = '%s'
               AND REFERENCED_TABLE_NAME IS NOT NULL",
            $table,
            $parentColumn
        ));

        $this->assertNotEmpty($rows, sprintf('%s: Der Fremdschlüssel auf %s fehlt.', $table, $parentColumn));
        $this->assertNotSame([], $this->indexColumns($table, $coveringIndex));
    }

    /**
     * @return list<string>
     */
    private function indexColumns(string $table, string $indexName): array
    {
        $rows = Capsule::connection()->select(sprintf(
            "SELECT COLUMN_NAME
             FROM information_schema.STATISTICS
             WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = '%s' AND INDEX_NAME = '%s'
             ORDER BY SEQ_IN_INDEX",
            $table,
            $indexName
        ));

        return array_map(static fn ($row): string => (string) $row->COLUMN_NAME, $rows);
    }
}
