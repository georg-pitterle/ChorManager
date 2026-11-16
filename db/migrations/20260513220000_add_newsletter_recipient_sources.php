<?php

declare(strict_types=1);

use Phinx\Migration\AbstractMigration;

/**
 * Die Empfängerquellen eines Newsletters bekommen eine eigene Tabelle;
 * `newsletters.event_id` fällt dafür weg.
 *
 * Der Rückbau kann nur den Weg zurückgehen, den das `up()` gekommen ist: Aus
 * jeder Quelle vom Typ `event_attendees` wird wieder ein `newsletters.event_id`.
 * Alles andere, was die Tabelle inzwischen trägt, passt in keine Spalte des
 * alten Modells - und steht nirgends sonst. Was dabei wirklich verloren ginge,
 * zählt UNRESTORABLE_SOURCES_SQL vor dem `drop()`; der Rest ist nach dem
 * Rückbau noch da oder wieder herzuleiten.
 */
final class AddNewsletterRecipientSources extends AbstractMigration
{
    /**
     * Die Quellen, die der Rückbau nicht unterbringt.
     *
     * Drei Fälle, und bewusst nur diese drei - gezählt wird, was wirklich weg
     * ist, nicht stumpf die ganze Tabelle:
     *
     * - `role` und `user` kennt das alte Modell überhaupt nicht. Beide kommen
     *   beim Rückbau von 20261004090300 aus den Zielgruppen-Filtern zurück in
     *   diese Tabelle und wären mit ihr sofort wieder weg.
     * - `project_members` hat das `up()` selbst aus `newsletters.project_id`
     *   eingesetzt, und diese Spalte überlebt den Rückbau. Solange die Nummer
     *   dort noch so steht, ist die Zeile herzuleiten und zählt nicht mit;
     *   weicht sie ab, ist sie eine spätere Angabe und geht verloren.
     * - Von mehreren `event_attendees` je Newsletter passt nur einer in die
     *   einzelne Spalte `newsletters.event_id`. Gezählt wird deshalb jeder
     *   außer dem ersten - genauso, wie 20260830120000 nur die Zuweisungen
     *   zählt, für die in `tasks.assigned_to` kein Platz ist.
     *
     * Die Anweisung steht als Konstante da, damit
     * `Tests\Feature\DestructiveMigrationGuardTest` genau die prüfen kann, die
     * auch läuft.
     */
    public const UNRESTORABLE_SOURCES_SQL =
        'SELECT COUNT(*) AS dropped
           FROM newsletter_recipient_sources s
           LEFT JOIN newsletters n ON n.id = s.newsletter_id
          WHERE s.source_type IN (\'role\', \'user\')
             OR (s.source_type = \'project_members\'
                 AND (n.id IS NULL OR n.project_id IS NULL OR n.project_id <> s.reference_id))
             OR (s.source_type = \'event_attendees\'
                 AND s.id <> (SELECT MIN(first.id)
                                FROM newsletter_recipient_sources first
                               WHERE first.newsletter_id = s.newsletter_id
                                 AND first.source_type = \'event_attendees\'))';

    public function up(): void
    {
        $this->table('newsletter_recipient_sources')
            ->addColumn('newsletter_id', 'integer', ['null' => false])
            ->addColumn('source_type', 'enum', ['values' => ['project_members', 'event_attendees', 'role', 'user']])
            ->addColumn('reference_id', 'integer', ['null' => false])
            ->addIndex(['newsletter_id'])
            ->addForeignKey(
                'newsletter_id',
                'newsletters',
                'id',
                ['delete' => 'CASCADE', 'update' => 'CASCADE']
            )
            ->create();

        $this->execute(
            "INSERT INTO newsletter_recipient_sources (newsletter_id, source_type, reference_id)
             SELECT id, 'project_members', project_id
             FROM newsletters"
        );

        $this->execute(
            "INSERT INTO newsletter_recipient_sources (newsletter_id, source_type, reference_id)
             SELECT id, 'event_attendees', event_id
             FROM newsletters
             WHERE event_id IS NOT NULL"
        );

        $this->table('newsletters')
            ->dropForeignKey('event_id')
            ->removeIndex(['event_id'])
            ->removeColumn('event_id')
            ->update();
    }

    public function down(): void
    {
        $this->table('newsletters')
            ->addColumn('event_id', 'integer', ['null' => true, 'after' => 'project_id'])
            ->addIndex(['event_id'])
            ->addForeignKey('event_id', 'events', 'id', ['delete' => 'SET_NULL', 'update' => 'NO_ACTION'])
            ->update();

        $this->execute(
            "UPDATE newsletters n
               INNER JOIN newsletter_recipient_sources s
               ON s.newsletter_id = n.id
              AND s.source_type = 'event_attendees'
             SET n.event_id = s.reference_id"
        );

        // Prüfung vor dem Löschen, und zwar nach dem Zurückschreiben: Was in
        // newsletters.event_id untergekommen ist, ist gerettet - was nicht,
        // steht nirgends sonst und wäre mit der Tabelle unwiederbringlich weg.
        $dropped = (int) ($this->fetchRow(self::UNRESTORABLE_SOURCES_SQL)['dropped'] ?? 0);

        if ($dropped > 0) {
            throw new RuntimeException(sprintf(
                'Rückbau abgebrochen: %d Empfängerquelle(n) passen in das alte Modell nicht und wären '
                    . 'mit newsletter_recipient_sources unwiederbringlich weg. Betroffen sind Quellen '
                    . 'vom Typ "role" oder "user", abweichende Projektquellen und jede Terminquelle '
                    . 'über der ersten je Newsletter. Diese zuerst bewusst selbst entfernen.',
                $dropped
            ));
        }

        $this->table('newsletter_recipient_sources')->drop()->save();
    }
}
