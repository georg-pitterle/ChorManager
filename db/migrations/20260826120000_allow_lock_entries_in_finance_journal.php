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
    /**
     * Zählt die Journaleinträge, die in die wieder engeren Spalten nicht mehr
     * passen: Sperr-Einträge und alles, was an keiner Buchung hängt.
     *
     * Steht als Konstante bereit, damit DestructiveMigrationGuardTest genau die
     * Anweisung prüfen kann, die hier auch ausgeführt wird - gleiches Muster wie
     * RepairFinanceAccountOpeningData::REPAIR_SQL.
     */
    public const UNFITTING_REVISIONS_SQL = <<<'SQL'
        SELECT COUNT(*) AS orphaned
        FROM finance_revisions
        WHERE action = 'lock'
           OR finance_id IS NULL
        SQL;

    public function up(): void
    {
        $this->table('finance_revisions')
            ->changeColumn('finance_id', 'integer', ['null' => true, 'default' => null])
            ->changeColumn('action', 'enum', ['values' => ['create', 'update', 'reverse', 'lock']])
            ->update();
    }

    public function down(): void
    {
        // Prüfung vor dem destruktiven Schritt: Einträge ohne Buchung passen
        // nicht in die engere Spalte, sie müssten also weg, bevor das NOT NULL
        // wieder greift. Genau das darf hier nicht still passieren - ein Journal,
        // das seine Einträge beim Zurückrollen verliert, ist als Nachweis nach
        // § 131 BAO wertlos.
        //
        // Der Lauf bricht deshalb ab und nennt ihre Zahl. Wer wirklich zurück
        // will, entscheidet selbst, was mit diesen Einträgen geschieht.
        $unfitting = (int) ($this->fetchRow(self::UNFITTING_REVISIONS_SQL)['orphaned'] ?? 0);

        if ($unfitting > 0) {
            throw new RuntimeException(sprintf(
                'Rollback blocked: %d Journaleintrag/-einträge sind Sperr-Einträge oder hängen an '
                    . 'keiner Buchung. Sie würden gelöscht und das Prüfjournal damit lückenhaft.',
                $unfitting
            ));
        }

        $this->table('finance_revisions')
            ->changeColumn('action', 'enum', ['values' => ['create', 'update', 'reverse']])
            ->changeColumn('finance_id', 'integer', ['null' => false])
            ->update();
    }
}
