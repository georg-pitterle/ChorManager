<?php

declare(strict_types=1);

namespace Tests\Feature;

use Illuminate\Database\Capsule\Manager as Capsule;
use Illuminate\Database\QueryException;
use PHPUnit\Framework\TestCase;
use Tests\Unit\Bootstrap;

/**
 * Das Prüfjournal der Finanzen hängt seit 20260923150000 über einen
 * Fremdschlüssel an der Buchung.
 *
 * Geprüft wird beides: dass der Constraint da ist und auf RESTRICT steht - und
 * dass er auch wirkt. Eine Buchung mit Journaleintrag darf sich nicht löschen
 * lassen; § 131 BAO verlangt, dass eine Korrektur den ursprünglichen Inhalt
 * nachvollziehbar lässt, und ein Journal, dessen Einträge ins Leere zeigen,
 * leistet das nicht.
 *
 * Der fehlende Fremdschlüssel auf user_id ist dagegen Absicht und wird hier
 * ausdrücklich festgehalten: 20260901121000 begründet ihn - das Journal soll
 * ein gelöschtes Mitglied überleben, deshalb steht dessen Name dort zusätzlich
 * als Momentaufnahme. Ein späterer Lauf soll ihn nicht "nachziehen".
 */
final class FinanceRevisionForeignKeyTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        Bootstrap::setupTestDatabase();
        Capsule::connection()->beginTransaction();
    }

    protected function tearDown(): void
    {
        Capsule::connection()->rollBack();
        parent::tearDown();
    }

    public function testFinanceIdIsProtectedByRestrictingForeignKey(): void
    {
        $rows = Capsule::connection()->select(
            "SELECT rc.DELETE_RULE, rc.REFERENCED_TABLE_NAME
             FROM information_schema.REFERENTIAL_CONSTRAINTS rc
             JOIN information_schema.KEY_COLUMN_USAGE k
               ON k.CONSTRAINT_SCHEMA = rc.CONSTRAINT_SCHEMA
              AND k.CONSTRAINT_NAME = rc.CONSTRAINT_NAME
             WHERE rc.CONSTRAINT_SCHEMA = DATABASE()
               AND rc.TABLE_NAME = 'finance_revisions'
               AND k.COLUMN_NAME = 'finance_id'"
        );

        $this->assertCount(1, $rows, 'finance_revisions.finance_id braucht genau einen Fremdschlüssel.');
        $this->assertSame('finances', $rows[0]->REFERENCED_TABLE_NAME);
        $this->assertSame(
            'RESTRICT',
            $rows[0]->DELETE_RULE,
            'CASCADE nähme die Journaleinträge mit, SET NULL kappte die Zuordnung - beides macht '
                . 'genau die Lücke auf, gegen die das Journal geführt wird.'
        );
    }

    /**
     * Die Spalte muss nullbar bleiben: Seit 20260826120000 trägt das Journal
     * auch Sperr-Einträge, die an keiner einzelnen Buchung hängen.
     */
    public function testLockEntriesWithoutBookingRemainPossible(): void
    {
        $column = Capsule::connection()->select(
            "SELECT IS_NULLABLE
             FROM information_schema.COLUMNS
             WHERE TABLE_SCHEMA = DATABASE()
               AND TABLE_NAME = 'finance_revisions'
               AND COLUMN_NAME = 'finance_id'"
        );

        $this->assertSame('YES', $column[0]->IS_NULLABLE);

        Capsule::connection()->insert(
            "INSERT INTO finance_revisions (finance_id, user_id, action, change_set, created_at)
             VALUES (NULL, NULL, 'lock', NULL, NOW())"
        );

        $this->assertSame(
            1,
            (int) Capsule::connection()->selectOne(
                "SELECT COUNT(*) AS c FROM finance_revisions WHERE action = 'lock'"
            )->c
        );
    }

    /**
     * Der Fremdschlüssel auf user_id fehlt bewusst - siehe 20260901121000.
     */
    public function testUserIdDeliberatelyHasNoForeignKey(): void
    {
        $rows = Capsule::connection()->select(
            "SELECT k.CONSTRAINT_NAME
             FROM information_schema.KEY_COLUMN_USAGE k
             WHERE k.CONSTRAINT_SCHEMA = DATABASE()
               AND k.TABLE_NAME = 'finance_revisions'
               AND k.COLUMN_NAME = 'user_id'
               AND k.REFERENCED_TABLE_NAME IS NOT NULL"
        );

        $this->assertSame(
            [],
            $rows,
            'Das Journal soll ein gelöschtes Mitglied überleben; sein Name steht dort als '
                . 'Momentaufnahme. Ein Fremdschlüssel würde den Eintrag löschen oder abkoppeln.'
        );
    }

    /**
     * Die Probe aufs Exempel: Solange ein Journaleintrag an der Buchung hängt,
     * weist die Datenbank das Löschen ab - nicht erst die Anwendung.
     */
    public function testBookingWithJournalEntryCannotBeDeleted(): void
    {
        $connection = Capsule::connection();
        $accountId = (int) $connection->selectOne(
            "SELECT id FROM finance_accounts WHERE type = 'cash' ORDER BY id LIMIT 1"
        )->id;

        $connection->insert(
            "INSERT INTO finances
                (running_number, type, amount, description, invoice_date, payment_date,
                 payment_method, finance_account_id)
             VALUES (?, 'income', '1.00', 'Fremdschlüssel-Probe', CURDATE(), CURDATE(), 'cash', ?)",
            [999999, $accountId]
        );
        $financeId = (int) $connection->getPdo()->lastInsertId();

        $connection->insert(
            "INSERT INTO finance_revisions (finance_id, user_id, action, change_set, created_at)
             VALUES (?, NULL, 'create', NULL, NOW())",
            [$financeId]
        );

        $this->expectException(QueryException::class);
        $connection->statement('DELETE FROM finances WHERE id = ?', [$financeId]);
    }
}
