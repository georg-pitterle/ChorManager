<?php

declare(strict_types=1);

use Phinx\Migration\AbstractMigration;

/**
 * Der Rollenname wird eindeutig.
 *
 * `RoleController::create()` und `::update()` fangen beide den Schlüsselkonflikt
 * (SQLSTATE 23000) ab und melden "Eine Rolle mit diesem Namen existiert
 * bereits." - `roles.name` trug dafür aber nie einen eindeutigen Index. Der
 * Zweig lief also nie an: Das zweite Anlegen ging durch, und in der
 * Rollenliste standen zwei gleich benannte Zeilen.
 *
 * Harmlos ist das nicht. Die Rolle ist der Träger der Rechte, und in der
 * Mitgliederverwaltung steht von ihr nur der Name. Zwei "Vorstand" mit
 * verschiedenen Rechtesätzen sind dort nicht auseinanderzuhalten - wer zuteilt,
 * sieht nicht, welchen der beiden er vergibt. Gefunden im Review-Lauf 83.
 *
 * Alle übrigen Stammdaten mit Namen halten das längst: `voice_groups`,
 * `sub_voices`, `repertoire_categories`, `finance_accounts`, `finance_groups`
 * und `sponsor_categories` tragen je einen eindeutigen Index auf `name`.
 *
 * Die Kollation `utf8mb4_unicode_ci` unterscheidet Groß- und Kleinschreibung
 * nicht: "Vorstand" und "vorstand" gelten damit als derselbe Name. Das ist
 * gewollt und entspricht der Begründung in 20261023120000.
 */
final class RequireUniqueRoleName extends AbstractMigration
{
    /**
     * Die Namen, die mehr als einmal vorkommen. Zusammengeführt wird nicht:
     * Welcher der beiden Rechtesätze gelten soll und welche Mitglieder
     * umzuhängen sind, steht in keiner Spalte - das entscheidet nur, wer die
     * Rollen angelegt hat.
     */
    private const DUPLICATE_NAMES_SQL =
        'SELECT name, COUNT(*) AS copies FROM roles GROUP BY name HAVING copies > 1 ORDER BY name';

    private const INDEX_NAME = 'uq_roles_name';

    public function up(): void
    {
        $duplicates = $this->fetchAll(self::DUPLICATE_NAMES_SQL);

        if ($duplicates !== []) {
            throw new RuntimeException(sprintf(
                'Eindeutiger Index auf roles.name nicht angelegt: %s kommen mehrfach vor. '
                . 'Die betroffenen Rollen zuerst von Hand zusammenführen oder umbenennen - '
                . 'welcher Rechtesatz gelten soll, sagt der Datenbestand nicht.',
                implode(', ', array_map(
                    static fn (array $row): string => sprintf('"%s" (%dx)', $row['name'], (int) $row['copies']),
                    $duplicates
                ))
            ));
        }

        $this->table('roles')
            ->addIndex(['name'], ['unique' => true, 'name' => self::INDEX_NAME])
            ->update();
    }

    public function down(): void
    {
        $this->table('roles')
            ->removeIndexByName(self::INDEX_NAME)
            ->update();
    }
}
