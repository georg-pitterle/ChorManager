<?php

declare(strict_types=1);

namespace Tests\Feature;

use Illuminate\Database\Capsule\Manager as Capsule;
use MoveSchemaToUnicodeCollation;
use PHPUnit\Framework\TestCase;
use Tests\Unit\Bootstrap;

/**
 * Der Kollisionswächter der Migration 20260923140000.
 *
 * Er soll den Lauf abbrechen, bevor zwei Werte unter `utf8mb4_unicode_ci`
 * zusammenfallen, die heute noch verschieden sind. Seine erste Fassung zählte
 * dabei auch `NULL`-Zeilen: Die landen in MySQL alle in einer Gruppe, und ab der
 * zweiten meldete er eine Kollision, die es nicht gab. Auf dem
 * Entwicklungsbestand brach die Migration daran ab - `finances` führt 602 Zeilen
 * ohne `import_hash`, die 44 echten Prüfsummen sind sämtlich verschieden.
 *
 * Ein eindeutiger Index in MySQL bindet eine Zeile nur, wenn jede indizierte
 * Spalte gefüllt ist; `NULL` darf beliebig oft vorkommen.
 *
 * Geprüft wird an einer temporären Tabelle statt am echten Schema: Der Wächter
 * soll für jeden Index gelten, nicht nur für den, an dem er aufgefallen ist.
 */
final class CollationGuardIgnoresNullRowsTest extends TestCase
{
    private const TABLE = 'collation_guard_probe';
    private const COLLATION = 'utf8mb4_unicode_ci';

    protected function setUp(): void
    {
        parent::setUp();
        Bootstrap::setupTestDatabase();

        require_once dirname(__DIR__, 2)
            . '/db/migrations/20260923140000_move_schema_to_unicode_collation.php';

        // Temporäre Tabelle: sichtbar nur in dieser Verbindung, und anders als
        // CREATE TABLE löst sie keine stille Festschreibung der Transaktion aus.
        Capsule::connection()->statement(
            'CREATE TEMPORARY TABLE `' . self::TABLE . '` (
                 id INT AUTO_INCREMENT PRIMARY KEY,
                 owner_id INT NULL,
                 label VARCHAR(50) NULL,
                 UNIQUE KEY uq_probe (owner_id, label)
             ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci'
        );
    }

    protected function tearDown(): void
    {
        Capsule::connection()->statement('DROP TEMPORARY TABLE IF EXISTS `' . self::TABLE . '`');

        parent::tearDown();
    }

    public function testManyEmptyRowsAreNoCollision(): void
    {
        $this->insert([
            [null, null],
            [null, null],
            [null, null],
        ]);

        $this->assertSame(0, $this->collisions());
    }

    /**
     * Auch eine halb gefüllte Zeile bindet der Index nicht - ein einziges `NULL`
     * in einer der Spalten genügt.
     */
    public function testAPartiallyEmptyRowIsNoCollision(): void
    {
        $this->insert([
            [7, null],
            [7, null],
            [null, 'Sopran'],
            [null, 'Sopran'],
        ]);

        $this->assertSame(0, $this->collisions());
    }

    /**
     * Die Gegenprobe: Unter `unicode_ci` ist 'ß' dasselbe wie das ausgeschriebene
     * Doppel-s. Würde der Wächter das durchgehen lassen, bräche später MySQL
     * selbst den Umbau ab - mitten im Lauf, mit halb umgestelltem Schema.
     */
    public function testARealCollisionIsStillReported(): void
    {
        $this->insert([
            [1, 'Straße'],
            [1, 'Strasse'], // naming:ascii - die Transliteration ist hier der Prüfgegenstand
        ]);

        $this->assertSame(1, $this->collisions());
    }

    /**
     * Verschiedene Werte in der Zahlenspalte trennen die Gruppen: Ein Index über
     * (owner_id, label) kollidiert nur innerhalb derselben owner_id.
     */
    public function testTheSameTextUnderDifferentOwnersIsNoCollision(): void
    {
        $this->insert([
            [1, 'Straße'],
            [2, 'Strasse'], // naming:ascii - dieselbe Transliteration, anderer Eigentümer
        ]);

        $this->assertSame(0, $this->collisions());
    }

    /**
     * Jede kollidierende Gruppe zählt einmal. Groß- und Kleinschreibung taugt
     * hier nicht als Beispiel: Die ist schon unter `general_ci` gleich, solche
     * Zeilen existieren also gar nicht erst nebeneinander.
     */
    public function testEveryCollidingGroupIsCountedOnce(): void
    {
        $this->insert([
            [3, 'Straße'],
            [3, 'Strasse'], // naming:ascii - die Transliteration ist der Prüfgegenstand
            [4, 'Grüße'],
            [4, 'Grüsse'],
        ]);

        $this->assertSame(2, $this->collisions());
    }

    /**
     * @param list<array{0: int|null, 1: string|null}> $rows
     */
    private function insert(array $rows): void
    {
        foreach ($rows as [$ownerId, $label]) {
            Capsule::connection()->insert(
                'INSERT INTO `' . self::TABLE . '` (owner_id, label) VALUES (?, ?)',
                [$ownerId, $label]
            );
        }
    }

    private function collisions(): int
    {
        $query = MoveSchemaToUnicodeCollation::collisionQuery(
            self::TABLE,
            ['owner_id' => null, 'label' => 'utf8mb4_general_ci'],
            self::COLLATION
        );

        $row = Capsule::connection()->selectOne($query);

        return (int) ($row->collided ?? 0);
    }
}
