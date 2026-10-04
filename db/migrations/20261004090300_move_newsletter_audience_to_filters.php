<?php

declare(strict_types=1);

use Phinx\Migration\AbstractMigration;

/**
 * Newsletter- und Vorlagen-Empfänger werden Zielgruppen-Filter: je Quelle
 * project_members, role oder user ein Filter mit genau einer Bedingung.
 * "Zielgruppe eines Termins" (event_attendees) bleibt als eigene Quelle in der
 * alten Tabelle; deren Enum kennt danach nur noch diesen Typ.
 */
final class MoveNewsletterAudienceToFilters extends AbstractMigration
{
    /** Quelltabelle => [Elternspalte, Besitzer-Spalte am Filter, Enum nullbar] */
    private const TABLES = [
        'newsletter_recipient_sources' => ['newsletter_id', 'newsletter_id', true],
        'newsletter_template_recipient_sources' => ['template_id', 'newsletter_template_id', false],
    ];

    private const CATEGORY_FOR_TYPE = [
        'project_members' => 'project',
        'role' => 'role',
        'user' => 'user',
    ];

    public function up(): void
    {
        $now = date('Y-m-d H:i:s');
        $pdo = $this->getAdapter()->getConnection();
        foreach (self::TABLES as $table => [$parent, $owner]) {
            $rows = $this->fetchAll(
                "SELECT {$parent} AS parent_id, source_type, reference_id FROM {$table}"
                . " WHERE source_type IN ('project_members', 'role', 'user') ORDER BY id"
            );
            foreach ($rows as $row) {
                $category = self::CATEGORY_FOR_TYPE[$row['source_type']] ?? null;
                if ($category === null) {
                    continue;
                }
                $this->execute(sprintf(
                    "INSERT INTO audience_filters (created_at, %s) VALUES ('%s', %d)",
                    $owner,
                    $now,
                    (int) $row['parent_id']
                ));
                $this->execute(sprintf(
                    'INSERT INTO audience_filter_conditions (audience_filter_id, category, reference_id)'
                    . " VALUES (%d, '%s', %d)",
                    (int) $pdo->lastInsertId(),
                    $category,
                    (int) $row['reference_id']
                ));
            }
        }

        // Prüfung vor den destruktiven Schritten: jede übertragbare Quelle hat
        // ihren Filter.
        foreach (self::TABLES as $table => [, $owner]) {
            $expected = (int) ($this->fetchRow(
                "SELECT COUNT(*) AS n FROM {$table} WHERE source_type IN ('project_members', 'role', 'user')"
            )['n'] ?? 0);
            $actual = (int) ($this->fetchRow(
                "SELECT COUNT(*) AS n FROM audience_filters WHERE {$owner} IS NOT NULL"
            )['n'] ?? 0);
            if ($expected !== $actual) {
                throw new RuntimeException(sprintf(
                    '%s: %d Quellen, aber %d Filter - Abbruch vor dem Entfernen der übertragenen Quellen.',
                    $table,
                    $expected,
                    $actual
                ));
            }
        }

        foreach (self::TABLES as $table => [, , $nullable]) {
            $this->execute("DELETE FROM {$table} WHERE source_type IS NULL OR source_type <> 'event_attendees'");
            $this->table($table)
                ->changeColumn('source_type', 'enum', ['values' => ['event_attendees'], 'null' => $nullable])
                ->update();
        }
    }

    public function down(): void
    {
        // Prüfung zuerst: Das alte Modell kennt je Quelle genau einen Wert und
        // weder Stimm- noch Untergruppen.
        foreach (self::TABLES as $table => [, $owner]) {
            $complex = (int) ($this->fetchRow(
                "SELECT COUNT(*) AS n FROM audience_filters f WHERE f.{$owner} IS NOT NULL AND (
                    (SELECT COUNT(*) FROM audience_filter_conditions c WHERE c.audience_filter_id = f.id) <> 1
                    OR EXISTS (SELECT 1 FROM audience_filter_conditions c
                               WHERE c.audience_filter_id = f.id AND c.category IN ('voice_group', 'sub_voice')))"
            )['n'] ?? 0);
            if ($complex > 0) {
                throw new RuntimeException(sprintf(
                    '%s: %d Filter sind kombiniert, leer oder nutzen Stimm- bzw. Untergruppen'
                    . ' - im alten Modell nicht darstellbar. Rückbau abgebrochen.',
                    $table,
                    $complex
                ));
            }
        }

        foreach (self::TABLES as $table => [$parent, $owner, $nullable]) {
            $this->table($table)
                ->changeColumn('source_type', 'enum', [
                    'values' => ['project_members', 'event_attendees', 'role', 'user'],
                    'null' => $nullable,
                ])
                ->update();
            $this->execute(
                "INSERT INTO {$table} ({$parent}, source_type, reference_id)
                 SELECT f.{$owner}, CASE c.category WHEN 'project' THEN 'project_members' ELSE c.category END,
                        c.reference_id
                 FROM audience_filters f JOIN audience_filter_conditions c ON c.audience_filter_id = f.id
                 WHERE f.{$owner} IS NOT NULL"
            );
            $this->execute("DELETE FROM audience_filters WHERE {$owner} IS NOT NULL");
        }
    }
}
