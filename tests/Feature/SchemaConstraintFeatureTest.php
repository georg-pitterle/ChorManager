<?php

declare(strict_types=1);

namespace Tests\Feature;

use Illuminate\Database\Capsule\Manager as Capsule;
use PHPUnit\Framework\TestCase;
use Tests\Unit\Bootstrap;

/**
 * Prüft die Zusicherungen aus den Migrationen 20260825120000 bis 20260825120200
 * direkt am Schema. Die drei Punkte kamen aus dem Review von db/migrations:
 *
 * - calendar_subscription_tokens hing ohne Fremdschlüssel an users und sammelte
 *   nach gelöschten Mitgliedern Karteileichen.
 * - Die beiden Zielgruppen-Tabellen liessen doppelte Quellen zu; verhindert hat
 *   das bisher nur die Anwendung.
 * - finances.payment_date war unindiziert, obwohl Kassabericht und
 *   Jahres-Kennzahlen darüber filtern.
 */
final class SchemaConstraintFeatureTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        Bootstrap::setupTestDatabase();
    }

    public function testCalendarSubscriptionTokensCascadeOnUserDelete(): void
    {
        $foreignKey = $this->foreignKeyFor('calendar_subscription_tokens', 'user_id');

        $this->assertNotNull(
            $foreignKey,
            'calendar_subscription_tokens.user_id braucht einen Fremdschlüssel auf users.'
        );
        $this->assertSame('users', $foreignKey['REFERENCED_TABLE_NAME']);
        $this->assertSame('id', $foreignKey['REFERENCED_COLUMN_NAME']);
        $this->assertSame(
            'CASCADE',
            $this->deleteRuleFor((string) $foreignKey['CONSTRAINT_NAME']),
            'Ein gelöschtes Mitglied muss sein Kalender-Token mitnehmen.'
        );
    }

    public function testEventAudienceSourcesRejectDuplicates(): void
    {
        $this->assertTrue(
            $this->hasUniqueIndex('event_audience_sources', ['event_id', 'source_type', 'reference_id']),
            'Dieselbe Zielgruppe darf an einem Termin nur einmal hängen.'
        );
    }

    public function testNewsletterRecipientSourcesRejectDuplicates(): void
    {
        $this->assertTrue(
            $this->hasUniqueIndex(
                'newsletter_recipient_sources',
                ['newsletter_id', 'source_type', 'reference_id']
            ),
            'Dieselbe Empfängerquelle darf an einem Newsletter nur einmal hängen.'
        );
    }

    public function testFinancesArePayableByDateWithoutFullScan(): void
    {
        $this->assertTrue(
            $this->hasIndexStartingWith('finances', 'payment_date'),
            'finances.payment_date trägt Kassabericht und Jahres-Kennzahlen und braucht einen Index.'
        );
    }

    /**
     * @return array<string, mixed>|null
     */
    private function foreignKeyFor(string $table, string $column): ?array
    {
        $row = Capsule::connection()->selectOne(
            'SELECT CONSTRAINT_NAME, REFERENCED_TABLE_NAME, REFERENCED_COLUMN_NAME
             FROM information_schema.KEY_COLUMN_USAGE
             WHERE TABLE_SCHEMA = DATABASE()
               AND TABLE_NAME = ?
               AND COLUMN_NAME = ?
               AND REFERENCED_TABLE_NAME IS NOT NULL
             LIMIT 1',
            [$table, $column]
        );

        return $row === null ? null : (array) $row;
    }

    private function deleteRuleFor(string $constraint): string
    {
        $row = Capsule::connection()->selectOne(
            'SELECT DELETE_RULE
             FROM information_schema.REFERENTIAL_CONSTRAINTS
             WHERE CONSTRAINT_SCHEMA = DATABASE()
               AND CONSTRAINT_NAME = ?
             LIMIT 1',
            [$constraint]
        );

        return $row === null ? '' : (string) ((array) $row)['DELETE_RULE'];
    }

    /**
     * @param list<string> $columns
     */
    private function hasUniqueIndex(string $table, array $columns): bool
    {
        foreach ($this->indexColumns($table, true) as $indexed) {
            if ($indexed === $columns) {
                return true;
            }
        }

        return false;
    }

    private function hasIndexStartingWith(string $table, string $column): bool
    {
        foreach ($this->indexColumns($table, false) as $indexed) {
            if (($indexed[0] ?? null) === $column) {
                return true;
            }
        }

        return false;
    }

    /**
     * Spalten je Index, in Index-Reihenfolge.
     *
     * @return array<string, list<string>>
     */
    private function indexColumns(string $table, bool $uniqueOnly): array
    {
        $rows = Capsule::connection()->select(
            'SELECT INDEX_NAME, COLUMN_NAME, NON_UNIQUE
             FROM information_schema.STATISTICS
             WHERE TABLE_SCHEMA = DATABASE()
               AND TABLE_NAME = ?
             ORDER BY INDEX_NAME, SEQ_IN_INDEX',
            [$table]
        );

        $indexes = [];
        foreach ($rows as $row) {
            $row = (array) $row;
            if ($uniqueOnly && (int) $row['NON_UNIQUE'] !== 0) {
                continue;
            }
            $indexes[(string) $row['INDEX_NAME']][] = (string) $row['COLUMN_NAME'];
        }

        return $indexes;
    }
}
