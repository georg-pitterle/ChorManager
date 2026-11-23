<?php

declare(strict_types=1);

use Phinx\Migration\AbstractMigration;

/**
 * Der Name einer Terminart wird eindeutig.
 *
 * `event_types.name` trug keinen eindeutigen Index, und das Formular prüfte
 * nichts: Zwei Terminarten "Probe" waren anlegbar. Im Termin-Formular steht von
 * der Terminart nur ihr Name - die beiden sind dort nicht zu unterscheiden, und
 * welche Farbe und welche Hervorhebung der Termin bekommt, hängt dann davon ab,
 * welche man zufällig erwischt.
 *
 * Damit ist `event_types` die letzte Stammdatentabelle mit Namen, die die
 * Zusicherung nachträgt: `voice_groups`, `sub_voices`, `repertoire_categories`,
 * `finance_accounts`, `finance_groups`, `sponsor_categories` und seit
 * 20261123120000 auch `roles` tragen sie. Gefunden im Review-Lauf 83,
 * entschieden in der Nacharbeit dazu.
 *
 * Die Kollation `utf8mb4_unicode_ci` unterscheidet Groß- und Kleinschreibung
 * nicht: "Probe" und "probe" gelten damit als derselbe Name. Das ist gewollt
 * und entspricht der Begründung in 20261023120000.
 */
final class RequireUniqueEventTypeName extends AbstractMigration
{
    /**
     * Die Namen, die mehrfach vorkommen. Zusammengeführt wird nicht: An einer
     * Terminart hängen Termine, und welche der beiden gelten soll - samt Farbe
     * und Hervorhebung -, sagt der Datenbestand nicht. Dasselbe Vorgehen wie in
     * 20261123120000 und 20261004090400.
     */
    private const DUPLICATE_NAMES_SQL =
        'SELECT name, COUNT(*) AS copies FROM event_types GROUP BY name HAVING copies > 1 ORDER BY name';

    private const INDEX_NAME = 'uq_event_types_name';

    public function up(): void
    {
        $duplicates = $this->fetchAll(self::DUPLICATE_NAMES_SQL);

        if ($duplicates !== []) {
            throw new RuntimeException(sprintf(
                'Eindeutiger Index auf event_types.name nicht angelegt: %s kommen mehrfach vor. '
                . 'Die betroffenen Terminarten zuerst von Hand umbenennen oder zusammenführen - '
                . 'welche davon gelten soll, sagt der Datenbestand nicht.',
                implode(', ', array_map(
                    static fn (array $row): string => sprintf('"%s" (%dx)', $row['name'], (int) $row['copies']),
                    $duplicates
                ))
            ));
        }

        $this->table('event_types')
            ->addIndex(['name'], ['unique' => true, 'name' => self::INDEX_NAME])
            ->update();
    }

    public function down(): void
    {
        $this->table('event_types')
            ->removeIndexByName(self::INDEX_NAME)
            ->update();
    }
}
