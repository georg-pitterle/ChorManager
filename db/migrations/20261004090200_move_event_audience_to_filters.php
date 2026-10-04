<?php

declare(strict_types=1);

use Phinx\Migration\AbstractMigration;

/**
 * Termin-Zielgruppen werden Filter: je alte Quelle ein Filter mit genau einer
 * Bedingung, ein Termin ohne Quelle bekommt einen Filter ohne Bedingung - er
 * galt für alle und gilt weiter für alle. An der Wirkung ändert sich nichts.
 */
final class MoveEventAudienceToFilters extends AbstractMigration
{
    private const CATEGORY_FOR_TYPE = [
        'role' => 'role',
        'voice_group' => 'voice_group',
        'user' => 'user',
        'project_members' => 'project',
    ];

    public function up(): void
    {
        $now = date('Y-m-d H:i:s');
        $pdo = $this->getAdapter()->getConnection();

        foreach ($this->fetchAll('SELECT id, event_id, source_type, reference_id FROM event_audience_sources') as $row) {
            $category = self::CATEGORY_FOR_TYPE[$row['source_type']] ?? null;
            if ($category === null) {
                continue;
            }
            $this->execute(sprintf(
                "INSERT INTO audience_filters (created_at, event_id) VALUES ('%s', %d)",
                $now,
                (int) $row['event_id']
            ));
            $this->execute(sprintf(
                "INSERT INTO audience_filter_conditions (audience_filter_id, category, reference_id) VALUES (%d, '%s', %d)",
                (int) $pdo->lastInsertId(),
                $category,
                (int) $row['reference_id']
            ));
        }

        $this->execute(sprintf(
            "INSERT INTO audience_filters (created_at, event_id)
             SELECT '%s', e.id FROM events e
             WHERE NOT EXISTS (SELECT 1 FROM event_audience_sources s WHERE s.event_id = e.id)",
            $now
        ));

        // Prüfung vor dem destruktiven Schritt: auswertbare Quellen 1:1 übertragen,
        // jeder Termin hat mindestens einen Filter. Unbekannte Quellarten trafen
        // bisher niemanden; sie gehen verloren und werden gezählt gemeldet.
        $expected = (int) ($this->fetchRow(
            "SELECT COUNT(*) AS n FROM event_audience_sources
             WHERE source_type IN ('role', 'voice_group', 'user', 'project_members')"
        )['n'] ?? 0);
        $emptyEvents = (int) ($this->fetchRow(
            'SELECT COUNT(*) AS n FROM events e
             WHERE NOT EXISTS (SELECT 1 FROM event_audience_sources s WHERE s.event_id = e.id)'
        )['n'] ?? 0);
        $actual = (int) ($this->fetchRow('SELECT COUNT(*) AS n FROM audience_filters WHERE event_id IS NOT NULL')['n'] ?? 0);
        $withoutFilter = (int) ($this->fetchRow(
            'SELECT COUNT(*) AS n FROM events e WHERE NOT EXISTS (SELECT 1 FROM audience_filters f WHERE f.event_id = e.id)'
        )['n'] ?? 0);
        // Termine, deren Quellen alle keinen auswertbaren Typ tragen, galten schon
        // bisher für niemanden. Sie bekommen bewusst keinen Filter (= niemand).
        $unevaluable = (int) ($this->fetchRow(
            "SELECT COUNT(*) AS n FROM events e
             WHERE EXISTS (SELECT 1 FROM event_audience_sources s WHERE s.event_id = e.id)
               AND NOT EXISTS (SELECT 1 FROM event_audience_sources s WHERE s.event_id = e.id
                   AND s.source_type IN ('role', 'voice_group', 'user', 'project_members'))"
        )['n'] ?? 0);
        if ($actual !== $expected + $emptyEvents || $withoutFilter > $unevaluable) {
            throw new RuntimeException(sprintf(
                'Termin-Zielgruppen unvollständig übertragen (erwartet %d Filter, vorhanden %d, Termine ohne Filter %d)'
                . ' - Abbruch vor dem Entfernen von event_audience_sources.',
                $expected + $emptyEvents,
                $actual,
                $withoutFilter
            ));
        }

        $this->table('event_audience_sources')->drop()->save();
    }

    public function down(): void
    {
        // Prüfung zuerst: Kombinierte Filter und Untergruppen kennt das alte Modell nicht.
        $complex = (int) ($this->fetchRow(
            "SELECT COUNT(*) AS n FROM audience_filters f WHERE f.event_id IS NOT NULL AND (
                (SELECT COUNT(*) FROM audience_filter_conditions c WHERE c.audience_filter_id = f.id) > 1
                OR EXISTS (SELECT 1 FROM audience_filter_conditions c
                           WHERE c.audience_filter_id = f.id AND c.category = 'sub_voice'))"
        )['n'] ?? 0);
        $allBesideOthers = (int) ($this->fetchRow(
            'SELECT COUNT(*) AS n FROM audience_filters f WHERE f.event_id IS NOT NULL
               AND NOT EXISTS (SELECT 1 FROM audience_filter_conditions c WHERE c.audience_filter_id = f.id)
               AND (SELECT COUNT(*) FROM audience_filters g WHERE g.event_id = f.event_id) > 1'
        )['n'] ?? 0);
        // Ein Termin ohne Filter trifft niemanden; ohne Quelle hieße er im alten
        // Modell "alle Mitglieder". Der Rückbau würde ihn öffnen.
        $withoutFilter = (int) ($this->fetchRow(
            'SELECT COUNT(*) AS n FROM events e WHERE NOT EXISTS (SELECT 1 FROM audience_filters f WHERE f.event_id = e.id)'
        )['n'] ?? 0);
        if ($withoutFilter > 0) {
            throw new RuntimeException(sprintf(
                '%d Termin(e) ohne Zielgruppe gelten für niemanden - im alten Modell hießen sie "alle".'
                . ' Rückbau abgebrochen.',
                $withoutFilter
            ));
        }
        if ($complex > 0 || $allBesideOthers > 0) {
            throw new RuntimeException(sprintf(
                '%d Termin-Filter sind kombiniert oder nutzen Untergruppen, %d stehen als "alle" neben weiteren'
                . ' - im alten Modell nicht darstellbar. Rückbau abgebrochen.',
                $complex,
                $allBesideOthers
            ));
        }

        $this->table('event_audience_sources')
            ->addColumn('event_id', 'integer', ['null' => false])
            ->addColumn('source_type', 'enum', [
                'values' => ['project_members', 'role', 'user', 'voice_group'],
                'null' => true,
            ])
            ->addColumn('reference_id', 'integer', ['null' => false])
            ->addIndex(['event_id', 'source_type', 'reference_id'], ['unique' => true, 'name' => 'uq_event_audience_source'])
            ->addForeignKey('event_id', 'events', 'id', ['delete' => 'CASCADE', 'update' => 'CASCADE'])
            ->create();

        $this->execute(
            "INSERT INTO event_audience_sources (event_id, source_type, reference_id)
             SELECT f.event_id,
                    CASE c.category WHEN 'project' THEN 'project_members' ELSE c.category END,
                    c.reference_id
             FROM audience_filters f JOIN audience_filter_conditions c ON c.audience_filter_id = f.id
             WHERE f.event_id IS NOT NULL"
        );
        $this->execute('DELETE FROM audience_filters WHERE event_id IS NOT NULL');
    }
}
