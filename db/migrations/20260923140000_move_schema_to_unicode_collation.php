<?php

declare(strict_types=1);

use Phinx\Migration\AbstractMigration;

/**
 * Stellt das ganze Schema auf `utf8mb4_unicode_ci` um.
 *
 * 20260923120000 hat die zweigeteilte Kollation zuerst auf `general_ci`
 * vereinheitlicht - das war die gefahrlose Richtung, nicht die richtige. Für
 * deutschen Text ist `unicode_ci` die bessere Wahl: Es sortiert Umlaute an der
 * Stelle, an der sie im Wörterbuch stehen, statt hinter Z, und es hält 'ß' und
 * 'ss' für denselben Text. Genau das soll eine Namenssuche im Chor auch tun.
 *
 * ## Warum das nicht einfach ein `CONVERT TO` ist
 *
 * `unicode_ci` hält mehr Zeichenfolgen für gleich als `general_ci`. Genau davon
 * lebt die Umstellung - und genau daran kann sie scheitern: Stehen in einer
 * Spalte mit eindeutigem Index heute zwei Werte, die sich nur in einer für
 * `unicode_ci` bedeutungslosen Weise unterscheiden, dann sind sie nachher
 * derselbe Wert. MySQL lehnt den Index-Aufbau dann ab, und zwar mitten im Lauf,
 * nachdem die vorherigen Tabellen bereits umgebaut sind.
 *
 * Die Prüfung steht deshalb **vor** dem ersten `CONVERT TO` und nimmt sich jeden
 * eindeutigen Index vor, an dem eine Textspalte beteiligt ist. Sie gruppiert die
 * Zeilen nach denselben Spalten, aber unter der Zielkollation; bleibt dabei eine
 * Gruppe mit mehr als einer Zeile übrig, bricht der Lauf ab und nennt Tabelle
 * und Index. Der Betreiber entscheidet dann, welcher der beiden Namen bleibt -
 * das ist nichts, was eine Migration still für ihn tun darf.
 *
 * Auf dem Bestand, an dem diese Migration entstanden ist, sowie auf der
 * Testdatenbank kollidiert nichts.
 *
 * ## Warum die Datenbank selbst mitwandert
 *
 * `ALTER DATABASE` ändert keine einzige bestehende Tabelle, sondern nur die
 * Vorgabe für künftige. Ohne sie entstünde bei jeder von Hand angelegten Tabelle
 * wieder die Zweiteilung, die 20260923120000 gerade beseitigt hat.
 */
final class MoveSchemaToUnicodeCollation extends AbstractMigration
{
    private const TARGET_COLLATION = 'utf8mb4_unicode_ci';
    private const PREVIOUS_COLLATION = 'utf8mb4_general_ci';

    /**
     * Phinx gehört seine Buchführungstabelle selbst; sie trägt keine
     * Anwendungsdaten und wird von keinem JOIN berührt.
     */
    private const FOREIGN_TABLE = 'phinxlog';

    public function up(): void
    {
        $this->convertSchema(self::TARGET_COLLATION);
    }

    /**
     * Die Rückrichtung ist gefahrlos: `general_ci` unterscheidet mehr
     * Zeichenfolgen als `unicode_ci`. Was unter der engeren Kollation in einen
     * eindeutigen Index passte, passt unter der weiteren erst recht hinein -
     * eine Kollisionsprüfung braucht es hier deshalb nicht.
     */
    public function down(): void
    {
        $this->convertSchema(self::PREVIOUS_COLLATION);
    }

    private function convertSchema(string $collation): void
    {
        $this->guardAgainstCollisions($collation);

        foreach ($this->applicationTables() as $table) {
            $this->execute(sprintf(
                'ALTER TABLE `%s` CONVERT TO CHARACTER SET utf8mb4 COLLATE %s',
                $table,
                $collation
            ));
        }

        $this->execute(sprintf(
            'ALTER DATABASE `%s` CHARACTER SET utf8mb4 COLLATE %s',
            $this->currentDatabase(),
            $collation
        ));
    }

    /**
     * Bricht ab, bevor die erste Tabelle angefasst wird.
     *
     * Geprüft wird jeder eindeutige Index, an dem mindestens eine Textspalte
     * beteiligt ist. Zahlenspalten gehen unverändert in die Gruppierung ein,
     * denn ein Index über (event_id, source_type) kollidiert nur innerhalb
     * derselben event_id.
     */
    private function guardAgainstCollisions(string $collation): void
    {
        $collisions = [];

        foreach ($this->uniqueIndexes() as $index) {
            $hasTextColumn = false;
            foreach ($index['columns'] as $columnCollation) {
                if ($columnCollation !== null) {
                    $hasTextColumn = true;
                    break;
                }
            }

            if (!$hasTextColumn) {
                continue;
            }

            $collided = (int) ($this->fetchRow(
                self::collisionQuery($index['table'], $index['columns'], $collation)
            )['collided'] ?? 0);

            if ($collided > 0) {
                $collisions[] = sprintf(
                    '%s.%s (%d Gruppe(n) über %s)',
                    $index['table'],
                    $index['name'],
                    $collided,
                    implode(', ', array_keys($index['columns']))
                );
            }
        }

        if ($collisions !== []) {
            throw new RuntimeException(sprintf(
                'Unter %s fallen Werte zusammen, die heute als verschieden gelten: %s. '
                    . 'Diese Einträge zuerst umbenennen oder zusammenlegen - welcher bleibt, '
                    . 'kann diese Migration nicht entscheiden.',
                $collation,
                implode('; ', $collisions)
            ));
        }
    }

    /**
     * Die Abfrage, die einen eindeutigen Index auf Kollisionen absucht.
     *
     * Öffentlich und statisch, damit sie prüfbar ist, ohne eine Migration
     * auszuführen - `tests/Feature/CollationGuardIgnoresNullRowsTest` stellt ihr
     * beide Fälle vor.
     *
     * Die `IS NOT NULL`-Bedingung über **alle** Spalten des Index ist der Kern:
     * MySQL bindet eine Zeile nur dann an einen eindeutigen Index, wenn jede
     * indizierte Spalte gefüllt ist; `NULL` darf beliebig oft vorkommen. Ohne
     * diese Bedingung zählte `COUNT(*)` alle `NULL`-Zeilen als eine Gruppe und
     * meldete sie ab der zweiten als Kollision. Auf dem Entwicklungsbestand
     * brach die Migration genau daran ab: `finances` führt 646 Zeilen, davon 602
     * ohne `import_hash`, und die 44 echten Prüfsummen sind sämtlich verschieden.
     *
     * @param array<string, string|null> $columns Spalte => Kollation (null bei Zahlen)
     */
    public static function collisionQuery(string $table, array $columns, string $collation): string
    {
        $expressions = [];
        $notNull = [];

        foreach ($columns as $column => $columnCollation) {
            $expressions[] = $columnCollation === null
                ? sprintf('`%s`', $column)
                : sprintf('CONVERT(`%s` USING utf8mb4) COLLATE %s', $column, $collation);

            $notNull[] = sprintf('`%s` IS NOT NULL', $column);
        }

        return sprintf(
            'SELECT COUNT(*) AS collided FROM (
                 SELECT 1 FROM `%s` WHERE %s GROUP BY %s HAVING COUNT(*) > 1
             ) AS doubled',
            $table,
            implode(' AND ', $notNull),
            implode(', ', $expressions)
        );
    }

    /**
     * Alle eindeutigen Indizes des Schemas, je Index die Spalten in ihrer
     * Reihenfolge und deren Kollation (`null` bei Nicht-Textspalten).
     *
     * @return list<array{table: string, name: string, columns: array<string, string|null>}>
     */
    private function uniqueIndexes(): array
    {
        $rows = $this->fetchAll(sprintf(
            "SELECT s.TABLE_NAME, s.INDEX_NAME, s.COLUMN_NAME, c.COLLATION_NAME
             FROM information_schema.STATISTICS s
             JOIN information_schema.COLUMNS c
               ON c.TABLE_SCHEMA = s.TABLE_SCHEMA
              AND c.TABLE_NAME = s.TABLE_NAME
              AND c.COLUMN_NAME = s.COLUMN_NAME
             WHERE s.TABLE_SCHEMA = DATABASE()
               AND s.NON_UNIQUE = 0
               AND s.TABLE_NAME <> '%s'
             ORDER BY s.TABLE_NAME, s.INDEX_NAME, s.SEQ_IN_INDEX",
            self::FOREIGN_TABLE
        ));

        $indexes = [];

        foreach ($rows as $row) {
            $key = $row['TABLE_NAME'] . '.' . $row['INDEX_NAME'];

            $indexes[$key] ??= [
                'table' => (string) $row['TABLE_NAME'],
                'name' => (string) $row['INDEX_NAME'],
                'columns' => [],
            ];

            $indexes[$key]['columns'][(string) $row['COLUMN_NAME']] = $row['COLLATION_NAME'] === null
                ? null
                : (string) $row['COLLATION_NAME'];
        }

        return array_values($indexes);
    }

    /**
     * @return list<string>
     */
    private function applicationTables(): array
    {
        $rows = $this->fetchAll(sprintf(
            "SELECT TABLE_NAME
             FROM information_schema.TABLES
             WHERE TABLE_SCHEMA = DATABASE()
               AND TABLE_TYPE = 'BASE TABLE'
               AND TABLE_NAME <> '%s'
             ORDER BY TABLE_NAME",
            self::FOREIGN_TABLE
        ));

        return array_map(static fn (array $row): string => (string) $row['TABLE_NAME'], $rows);
    }

    private function currentDatabase(): string
    {
        return (string) ($this->fetchRow('SELECT DATABASE() AS name')['name'] ?? '');
    }
}
