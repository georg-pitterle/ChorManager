<?php

declare(strict_types=1);

namespace Tests\Unit\Migrations;

use PHPUnit\Framework\TestCase;
use ReflectionClass;

/**
 * 20260923120000 zieht die über die Phinx-API entstandenen Tabellen auf die
 * Kollation des übrigen Schemas nach und bricht danach ab, wenn noch eine
 * Tabelle ausschert. Welche Tabellen das sind, steht als Liste in der Migration.
 *
 * Diese Liste war unvollständig, und zwar auf genau einer Art von Bestand:
 * `password_resets` und `remember_logins` entstanden ursprünglich über die
 * Phinx-API (`20260317000000_add_password_resets`,
 * `20260321110000_create_remember_logins`). Beide Migrationen wurden in
 * fcac134 ("Konsolidiere Datenbank-Migrationen") in die Ursprungsmigration
 * eingeschmolzen, dort aber als rohes `CREATE TABLE` mit ausgeschriebenem
 * `COLLATE=utf8mb4_general_ci`.
 *
 * Damit hängt die Kollation dieser zwei Tabellen daran, wann eine Installation
 * angelegt wurde:
 *
 * - vor der Konsolidierung: `utf8mb4_unicode_ci` (Phinx-Vorgabe)
 * - danach: `utf8mb4_general_ci` (Ursprungsmigration)
 *
 * Auf einem älteren Bestand - dem Produktivbestand - lief 20260923120000
 * deshalb in seinen eigenen Wächter und riss beim Start die Anwendung mit:
 * "Diese Tabellen stehen weiterhin nicht auf utf8mb4_general_ci:
 * password_resets (utf8mb4_unicode_ci), remember_logins (utf8mb4_unicode_ci)".
 *
 * Der Test liest die Liste statisch und braucht keine Datenbank; er prüft die
 * Vollständigkeit, die auf einem frischen Bestand niemand bemerkt.
 */
final class CollationUnificationCoversConsolidatedTablesTest extends TestCase
{
    private const MIGRATION_FILE = __DIR__
        . '/../../../db/migrations/20260923120000_unify_table_collation.php';

    /**
     * Tabellen, die vor fcac134 über die Phinx-API angelegt wurden und auf
     * einem Bestand aus dieser Zeit deshalb `utf8mb4_unicode_ci` tragen.
     */
    private const CONSOLIDATED_PHINX_TABLES = [
        'password_resets',
        'remember_logins',
    ];

    public function testConsolidatedPhinxTablesAreConverted(): void
    {
        $tables = $this->declaredTables();

        foreach (self::CONSOLIDATED_PHINX_TABLES as $table) {
            $this->assertContains(
                $table,
                $tables,
                sprintf(
                    '%s entstand vor der Migrations-Konsolidierung über die Phinx-API und trägt auf '
                        . 'einem Bestand aus dieser Zeit utf8mb4_unicode_ci. Fehlt die Tabelle in '
                        . 'PHINX_CREATED_TABLES, bricht 20260923120000 dort in seinem eigenen Wächter ab.',
                    $table
                )
            );
        }
    }

    /**
     * @return list<string>
     */
    private function declaredTables(): array
    {
        require_once self::MIGRATION_FILE;

        /** @var list<string> $tables */
        $tables = (new ReflectionClass('UnifyTableCollation'))
            ->getConstant('PHINX_CREATED_TABLES');

        return $tables;
    }
}
