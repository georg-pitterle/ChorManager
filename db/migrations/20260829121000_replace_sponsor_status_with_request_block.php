<?php

declare(strict_types=1);

use Phinx\Migration\AbstractMigration;

/**
 * Ein Sponsor hatte bisher einen eigenen Status neben dem Status jeder
 * Vereinbarung. Das war doppelte Pflege mit widersprüchlichem Ergebnis: das
 * Dashboard zählte "aktive Sponsoren" aus `sponsors.status`, während der
 * eigentliche Vorgang an der Vereinbarung hing. Der Zustand eines Sponsors
 * ergibt sich künftig aus seinen Vereinbarungen; festzuhalten bleibt nur die
 * Generalabsage ("bitte gar nicht mehr anfragen").
 */
final class ReplaceSponsorStatusWithRequestBlock extends AbstractMigration
{
    private const STATUS_NOTE_PREFIX = 'Bisheriger Sponsor-Status: ';
    private const BLOCK_NOTE_PREFIX = 'Keine Anfragen erwünscht: ';

    /** @var array<string, string> */
    private const STATUS_LABELS = [
        'prospect' => 'Interessent',
        'contacted' => 'Kontaktiert',
        'negotiating' => 'Verhandlung',
        'active' => 'Aktiv',
        'paused' => 'Pausiert',
        'closed' => 'Abgeschlossen',
    ];

    public function up(): void
    {
        $this->table('sponsors')
            ->addColumn('requests_blocked', 'boolean', [
                'null' => false,
                'default' => false,
                'after' => 'notes',
                'comment' => 'Generalabsage: dieser Sponsor möchte nicht mehr angefragt werden',
            ])
            ->addColumn('requests_blocked_note', 'text', [
                'null' => true,
                'default' => null,
                'after' => 'requests_blocked',
            ])
            ->update();

        // Der Status wandert in die Notizen, bevor die Spalte fällt - sonst ist
        // die Information nach dieser Migration ersatzlos weg.
        foreach (self::STATUS_LABELS as $status => $label) {
            $this->execute(sprintf(
                "UPDATE sponsors
                 SET notes = CONCAT(COALESCE(CONCAT(NULLIF(notes, ''), '\n'), ''), '%s%s')
                 WHERE status = '%s'",
                self::STATUS_NOTE_PREFIX,
                $label,
                $status
            ));
        }

        $unsaved = (int) ($this->fetchRow(sprintf(
            "SELECT COUNT(*) AS unsaved FROM sponsors WHERE notes NOT LIKE '%%%s%%' OR notes IS NULL",
            self::STATUS_NOTE_PREFIX
        ))['unsaved'] ?? 0);

        if ($unsaved > 0) {
            throw new RuntimeException(sprintf(
                'Bei %d Sponsor(en) konnte der bisherige Status nicht in den Notizen gesichert werden. '
                    . 'Die Spalte sponsors.status wird deshalb nicht entfernt.',
                $unsaved
            ));
        }

        $this->table('sponsors')
            ->removeColumn('status')
            ->update();
    }

    /**
     * Legt die Spalte mit dem Standardwert neu an. Die alten Statuswerte stehen
     * als Zeile in den Notizen und werden bewusst nicht zurückgeschrieben -
     * welcher Eintrag zu welchem Lauf gehört, lässt sich dort nicht sicher
     * erkennen.
     *
     * Die Generalabsage dagegen wird gesichert, bevor ihre Spalten fallen. Sie
     * ist der einzige Grund, aus dem es diese Migration gibt; ginge sie beim
     * Zurückrollen verloren, käme jeder gesperrte Sponsor als "prospect" zurück
     * - also genau in dem Zustand, der zu neuen Anfragen einlädt.
     */
    public function down(): void
    {
        $this->execute(sprintf(
            "UPDATE sponsors
             SET notes = CONCAT(
                 COALESCE(CONCAT(NULLIF(notes, ''), '\n'), ''),
                 '%s',
                 COALESCE(NULLIF(requests_blocked_note, ''), 'ohne Begründung')
             )
             WHERE requests_blocked = 1",
            self::BLOCK_NOTE_PREFIX
        ));

        $unsaved = (int) ($this->fetchRow(sprintf(
            "SELECT COUNT(*) AS unsaved FROM sponsors
             WHERE requests_blocked = 1 AND (notes IS NULL OR notes NOT LIKE '%%%s%%')",
            self::BLOCK_NOTE_PREFIX
        ))['unsaved'] ?? 0);

        if ($unsaved > 0) {
            throw new RuntimeException(sprintf(
                'Bei %d Sponsor(en) konnte die Sperre nicht in den Notizen gesichert werden. '
                    . 'Die Spalten bleiben deshalb erhalten.',
                $unsaved
            ));
        }

        $this->table('sponsors')
            ->addColumn('status', 'enum', [
                'values' => array_keys(self::STATUS_LABELS),
                'null' => false,
                'default' => 'prospect',
                'after' => 'notes',
            ])
            ->update();

        $this->table('sponsors')
            ->removeColumn('requests_blocked')
            ->removeColumn('requests_blocked_note')
            ->update();
    }
}
