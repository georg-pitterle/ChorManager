<?php

declare(strict_types=1);

namespace Tests\Feature;

use Illuminate\Database\Capsule\Manager as Capsule;
use PHPUnit\Framework\TestCase;
use Tests\Unit\Bootstrap;

/**
 * Das Schema muss durchgehend dieselbe Kollation tragen.
 *
 * Bis 20260923120000 war es zweigeteilt: Tabellen aus rohem `CREATE TABLE`
 * (die Ursprungsmigration schreibt `COLLATE=utf8mb4_general_ci` dreißigmal
 * aus) standen auf `utf8mb4_general_ci`, Tabellen aus der Phinx-API dagegen auf
 * `utf8mb4_unicode_ci` - Phinx setzt diese Vorgabe im MysqlAdapter selbst, und
 * `phinx.php` gab keine eigene vor.
 *
 * Das ist keine Frage des Geschmacks. Vergleicht MySQL zwei Textspalten
 * unterschiedlicher Kollation, bricht die Abfrage hart ab:
 *
 *     SELECT u.email FROM users u
 *     JOIN mail_queue m ON m.recipient_email = u.email
 *     -- ERROR 1267: Illegal mix of collations
 *
 * Kein Codepfad tat das bisher, aber jeder künftige JOIN über die Grenze wäre
 * darauf gelaufen - und zwar erst zur Laufzeit. Dazu kommt, dass die beiden
 * Kollationen deutschen Text verschieden vergleichen: in `unicode_ci` gilt
 * 'ß' = 'ss', in `general_ci` nicht. Gleichnamigkeit bedeutete damit in der
 * einen Hälfte des Schemas etwas anderes als in der anderen.
 *
 * Geprüft wird deshalb nicht ein fester Satz Tabellen, sondern das ganze
 * Schema: Jede neue Tabelle fällt hier auf, sobald sie ausschert.
 */
final class SchemaCollationIsUniformTest extends TestCase
{
    /**
     * Die Kollation des Schemas. Sie stammt aus der Ursprungsmigration und gilt
     * für die große Mehrheit der Tabellen; 20260923120000 hat die Ausreißer
     * nachgezogen.
     */
    private const EXPECTED_COLLATION = 'utf8mb4_general_ci';

    /**
     * Phinx legt seine Buchführungstabelle selbst an und pflegt sie auch selbst.
     * Sie trägt keine Anwendungsdaten und wird von keinem JOIN berührt, deshalb
     * steht sie außerhalb der Regel.
     */
    private const FOREIGN_TABLES = ['phinxlog'];

    protected function setUp(): void
    {
        Bootstrap::setupTestDatabase();
    }

    public function testEveryTableUsesTheSchemaCollation(): void
    {
        $deviating = [];

        foreach ($this->tableCollations() as $table => $collation) {
            if ($collation !== self::EXPECTED_COLLATION) {
                $deviating[] = sprintf('%s (%s)', $table, $collation);
            }
        }

        $this->assertSame(
            [],
            $deviating,
            sprintf(
                'Diese Tabellen scheren aus %s aus: %s. Ein JOIN über zwei Textspalten '
                    . 'verschiedener Kollation bricht mit "Illegal mix of collations" ab.',
                self::EXPECTED_COLLATION,
                implode(', ', $deviating)
            )
        );
    }

    /**
     * Auch die Textspalten selbst müssen mitziehen: `ALTER TABLE ... CONVERT TO`
     * ändert sie mit, eine einzeln abweichend angelegte Spalte bliebe von der
     * Tabellenprüfung darüber aber unbemerkt - und genau sie würde den JOIN zum
     * Abbruch bringen.
     */
    public function testEveryTextColumnUsesTheSchemaCollation(): void
    {
        $placeholders = implode(', ', array_fill(0, count(self::FOREIGN_TABLES), '?'));

        $rows = Capsule::connection()->select(
            sprintf(
                'SELECT TABLE_NAME, COLUMN_NAME, COLLATION_NAME
                 FROM information_schema.COLUMNS
                 WHERE TABLE_SCHEMA = DATABASE()
                   AND COLLATION_NAME IS NOT NULL
                   AND COLLATION_NAME <> ?
                   AND TABLE_NAME NOT IN (%s)
                 ORDER BY TABLE_NAME, COLUMN_NAME',
                $placeholders
            ),
            array_merge([self::EXPECTED_COLLATION], self::FOREIGN_TABLES)
        );

        $deviating = array_map(
            static fn ($row): string => sprintf(
                '%s.%s (%s)',
                $row->TABLE_NAME,
                $row->COLUMN_NAME,
                $row->COLLATION_NAME
            ),
            $rows
        );

        $this->assertSame(
            [],
            $deviating,
            sprintf('Diese Spalten scheren aus %s aus: %s.', self::EXPECTED_COLLATION, implode(', ', $deviating))
        );
    }

    /**
     * Die Probe aufs Exempel: der JOIN, an dem die Zweiteilung aufgefallen ist.
     * Er verbindet eine Tabelle aus rohem `CREATE TABLE` mit einer aus der
     * Phinx-API und lief vor 20260923120000 in ERROR 1267.
     */
    public function testJoinAcrossBothSchemaHalvesRuns(): void
    {
        $rows = Capsule::connection()->select(
            'SELECT u.email
             FROM users u
             JOIN mail_queue m ON m.recipient_email = u.email
             LIMIT 1'
        );

        $this->assertIsArray($rows);
    }

    /**
     * @return array<string, string>
     */
    private function tableCollations(): array
    {
        $placeholders = implode(', ', array_fill(0, count(self::FOREIGN_TABLES), '?'));

        $rows = Capsule::connection()->select(
            sprintf(
                "SELECT TABLE_NAME, TABLE_COLLATION
                 FROM information_schema.TABLES
                 WHERE TABLE_SCHEMA = DATABASE()
                   AND TABLE_TYPE = 'BASE TABLE'
                   AND TABLE_NAME NOT IN (%s)
                 ORDER BY TABLE_NAME",
                $placeholders
            ),
            self::FOREIGN_TABLES
        );

        $collations = [];

        foreach ($rows as $row) {
            $collations[(string) $row->TABLE_NAME] = (string) $row->TABLE_COLLATION;
        }

        return $collations;
    }
}
