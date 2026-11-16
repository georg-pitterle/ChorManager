<?php

declare(strict_types=1);

namespace Tests\Unit\Migrations;

use MigrateSongsToProjectAssignments;
use PHPUnit\Framework\TestCase;
use RuntimeException;

require_once dirname(__DIR__, 3) . '/db/migrations/20260421110000_migrate_songs_to_project_assignments.php';

/**
 * `phinx rollback` arbeitet die Kette von hinten ab, und das `down()` von
 * 20260421110000 bricht unbedingt ab. Dort endet jeder Rückbau, die zehn
 * Migrationen mit älterem Zeitstempel sind also unerreichbar.
 *
 * Darauf stützt sich eine Entscheidung aus Lauf 76: Diesen zehn `down()` wird
 * keine Zählabfrage mehr nachgetragen, obwohl mindestens eines - das von
 * 20260421100000 - drei gefüllte Tabellen ungezählt wegwirft. Eine Prüfung
 * dort könnte nie laufen.
 *
 * Die Entscheidung hält nur, solange der Boden hält. Wird dieses `down()`
 * jemals umkehrbar, werden die zehn `down()` darunter wieder erreichbar - und
 * dann fehlen dort Prüfungen, ohne dass es jemandem auffällt. Genau diesen
 * Moment hält dieser Test fest.
 *
 * Geprüft wird die Migration selbst, nicht ihr Wortlaut: Ein Test, der nur nach
 * `throw` im Quelltext sucht, bliebe grün, wenn das `throw` in einem toten
 * Zweig landet. `newInstanceWithoutConstructor()` genügt, weil das `down()`
 * abbricht, bevor es einen Adapter braucht.
 */
final class RollbackFloorTest extends TestCase
{
    private const FLOOR = '20260421110000';

    public function testTheFloorOfTheChainRefusesToRollBack(): void
    {
        $migration = (new \ReflectionClass(MigrateSongsToProjectAssignments::class))
            ->newInstanceWithoutConstructor();

        $this->expectException(RuntimeException::class);

        $migration->down();
    }

    /**
     * Der Klassenkommentar dort nennt die Zahl der unerreichbaren Migrationen.
     * Kommt eine mit älterem Zeitstempel dazu, stimmt sie nicht mehr - und
     * jemand hätte unbemerkt eine Migration in den toten Teil der Kette
     * gelegt, wo ihr `down()` nie läuft.
     */
    public function testExactlyTenMigrationsSitBelowTheFloor(): void
    {
        $files = glob(dirname(__DIR__, 3) . '/db/migrations/*.php');
        self::assertIsArray($files, 'Migrationsverzeichnis nicht lesbar.');

        $below = array_values(array_filter(
            array_map(static fn (string $file): string => basename($file), $files),
            static fn (string $name): bool => substr($name, 0, 14) < self::FLOOR
        ));

        $this->assertCount(
            10,
            $below,
            sprintf(
                "Unter dem Boden %s liegen nicht mehr zehn Migrationen, sondern diese:\n%s\n"
                    . 'Ihr down() ist unerreichbar. Entweder gehört die neue Migration über den '
                    . 'Boden, oder der Klassenkommentar in %s nennt die falsche Zahl.',
                self::FLOOR,
                implode("\n", $below),
                self::FLOOR
            )
        );
    }
}
