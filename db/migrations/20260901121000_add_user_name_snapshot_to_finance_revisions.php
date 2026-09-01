<?php

declare(strict_types=1);

use Phinx\Migration\AbstractMigration;

/**
 * Hält den Namen der handelnden Person im Prüfjournal fest.
 *
 * finance_revisions kennt bisher nur die user_id, und zwar ohne Fremdschlüssel.
 * Wird ein Mitglied gelöscht, zeigen seine früheren Einträge deshalb "System" -
 * dieselbe Anzeige wie ein Eintrag ganz ohne Person. Wer eine Buchung erfasst,
 * geändert oder storniert hat, ist damit nicht mehr feststellbar, obwohl genau
 * das der Zweck des Journals ist (§ 131 BAO).
 *
 * Der Name wird deshalb beim Schreiben mitgespeichert. Vor- und Nachname stehen
 * getrennt, damit die Anzeige weiter der eingestellten Namensreihenfolge folgt
 * (NameFormatterService) statt eine beim Schreiben gewählte einzufrieren.
 *
 * Die user_id bleibt: Sie verweist weiterhin auf das Mitglied, solange es
 * existiert. Ein Fremdschlüssel kommt bewusst nicht dazu - ON DELETE CASCADE
 * würde Journaleinträge löschen, ON DELETE SET NULL die Zuordnung kappen. Beides
 * widerspricht einem Journal, das gerade das Nachvollziehen sichern soll.
 */
final class AddUserNameSnapshotToFinanceRevisions extends AbstractMigration
{
    public function up(): void
    {
        $this->table('finance_revisions')
            ->addColumn('user_first_name', 'string', [
                'limit' => 255,
                'null' => true,
                'default' => null,
                'after' => 'user_id',
            ])
            ->addColumn('user_last_name', 'string', [
                'limit' => 255,
                'null' => true,
                'default' => null,
                'after' => 'user_first_name',
            ])
            ->update();

        // Bestandseinträge nachziehen, solange ihre Mitglieder noch da sind.
        // Für bereits gelöschte Mitglieder ist der Name nicht rekonstruierbar;
        // diese Einträge bleiben leer und zeigen weiterhin "System".
        $this->execute(
            'UPDATE finance_revisions r
               INNER JOIN users u ON u.id = r.user_id
             SET r.user_first_name = u.first_name,
                 r.user_last_name = u.last_name'
        );
    }

    public function down(): void
    {
        // Prüfung vor dem destruktiven Schritt: Für Einträge, deren Mitglied
        // inzwischen gelöscht ist, steht der Name nur noch hier. Mit den Spalten
        // ginge genau die Nachvollziehbarkeit verloren, für die es sie gibt -
        // und zurückschreiben lässt sie sich nirgendwo, es gibt keine Zeile
        // mehr, die den Namen aufnehmen könnte.
        $orphanedNames = (int) ($this->fetchRow(
            "SELECT COUNT(*) AS orphaned
             FROM finance_revisions r
             LEFT JOIN users u ON u.id = r.user_id
             WHERE u.id IS NULL
               AND (
                   COALESCE(r.user_first_name, '') <> ''
                   OR COALESCE(r.user_last_name, '') <> ''
               )"
        )['orphaned'] ?? 0);

        if ($orphanedNames > 0) {
            throw new RuntimeException(sprintf(
                'Rollback blocked: %d Journaleintrag/-einträge nennen eine Person, die es nicht mehr gibt. '
                    . 'Ihr Name steht nur in diesen Spalten - er wäre danach unwiederbringlich weg.',
                $orphanedNames
            ));
        }

        $this->table('finance_revisions')
            ->removeColumn('user_first_name')
            ->removeColumn('user_last_name')
            ->update();
    }
}
