<?php

declare(strict_types=1);

use Phinx\Migration\AbstractMigration;

/**
 * Der Buchungsabschluss (finance_closed_until) war bisher nur im Anwendungslog
 * vermerkt. Er entscheidet aber darüber, ob ein Zeitraum noch veränderbar ist,
 * und lässt sich zurückdatieren - genau die Art Eingriff, die § 131 BAO
 * nachvollziehbar verlangt. Er gehört deshalb ins Prüfjournal.
 *
 * Ein solcher Eintrag hängt an keiner einzelnen Buchung: finance_id wird
 * nullbar, und die Aktionsliste bekommt "lock".
 */
final class AllowLockEntriesInFinanceJournal extends AbstractMigration
{
    public function up(): void
    {
        $this->table('finance_revisions')
            ->changeColumn('finance_id', 'integer', ['null' => true, 'default' => null])
            ->changeColumn('action', 'enum', ['values' => ['create', 'update', 'reverse', 'lock']])
            ->update();
    }

    public function down(): void
    {
        // Einträge ohne Buchung passen nicht in die engere Spalte; sie werden
        // vor der Rücknahme entfernt, sonst scheitert das NOT NULL.
        $this->execute("DELETE FROM finance_revisions WHERE action = 'lock' OR finance_id IS NULL");

        $this->table('finance_revisions')
            ->changeColumn('action', 'enum', ['values' => ['create', 'update', 'reverse']])
            ->changeColumn('finance_id', 'integer', ['null' => false])
            ->update();
    }
}
