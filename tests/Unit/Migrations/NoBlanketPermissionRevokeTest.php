<?php

declare(strict_types=1);

namespace Tests\Unit\Migrations;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * Ein Backfill vergibt ein neues Recht an die Rollen, die es faktisch schon
 * hatten. Sein `down()` darf es ihnen nicht pauschal wieder wegnehmen: Zwischen
 * Hin- und Rückweg liegt der reguläre Betrieb, und was dort über die
 * Rollenmatrix vergeben wurde, ist von der Datenbank nicht mehr davon zu
 * unterscheiden, was der Backfill gesetzt hat. Ein `UPDATE roles SET <recht> = 0`
 * ohne WHERE löscht deshalb auch handvergebene Rechte.
 *
 * 20260731090100 und 20260731090300 halten das seit jeher so und lassen ihr
 * `down()` bewusst leer. Der Test zieht die Regel für alle Migrationen nach.
 */
final class NoBlanketPermissionRevokeTest extends TestCase
{
    private const MIGRATION_DIR = __DIR__ . '/../../../db/migrations';

    /**
     * @return array<string, array{0: string}>
     */
    public static function migrationFileProvider(): array
    {
        $files = glob(self::MIGRATION_DIR . '/*.php');
        self::assertNotFalse($files, 'Migrationsverzeichnis nicht lesbar.');
        self::assertNotSame([], $files, 'Keine Migrationen gefunden.');

        $cases = [];
        foreach ($files as $file) {
            $cases[basename($file)] = [$file];
        }

        return $cases;
    }

    #[DataProvider('migrationFileProvider')]
    public function testNoMigrationRevokesAPermissionFromEveryRole(string $file): void
    {
        $source = file_get_contents($file);
        self::assertIsString($source, 'Migration nicht lesbar: ' . $file);

        $found = preg_match_all(
            '/UPDATE\s+roles\s+SET\s+(can_\w+)\s*=\s*0\s*(?<tail>["\']|;)/i',
            $source,
            $matches,
            PREG_SET_ORDER
        );
        self::assertNotFalse($found, 'Muster nicht auswertbar: ' . $file);

        $blanket = [];
        foreach ($matches as $match) {
            $blanket[] = $match[1];
        }

        $this->assertSame(
            [],
            $blanket,
            sprintf(
                '%s: entzieht %s allen Rollen auf einmal. Ein down() ohne WHERE löscht auch '
                    . 'Rechte, die nach dem Backfill von Hand vergeben wurden - es bleibt deshalb leer, '
                    . 'wie in 20260731090100 und 20260731090300.',
                basename($file),
                implode(', ', $blanket)
            )
        );
    }
}
