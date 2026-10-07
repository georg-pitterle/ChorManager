<?php

declare(strict_types=1);

namespace Tests\Feature;

use PHPUnit\Framework\TestCase;

/**
 * Form der Speicherübersicht: harte Zeilengrenze der Twig-Regeln und die
 * gestapelte Tabellenansicht auf dem Handy.
 */
final class StorageLayoutFeatureTest extends TestCase
{
    private const MAX_LINE = 130;

    private static function read(string $relative): string
    {
        return (string) file_get_contents(dirname(__DIR__, 2) . '/' . $relative);
    }

    /**
     * @return list<string>
     */
    private static function longLines(string $content): array
    {
        $long = [];
        foreach (explode("\n", $content) as $number => $line) {
            if (mb_strlen($line) > self::MAX_LINE) {
                $long[] = ($number + 1) . ': ' . mb_strlen($line);
            }
        }

        return $long;
    }

    public function testStorageTemplatesStayWithinTheLineLimit(): void
    {
        $this->assertSame([], self::longLines(self::read('templates/storage/index.twig')));

        $dashboard = self::read('templates/dashboard/index.twig');
        $start = (int) strpos($dashboard, '{% if storage_tile %}');
        $end = (int) strpos($dashboard, '<a href="/storage"', $start);
        $this->assertGreaterThan($start, $end, 'Speicher-Kachel nicht gefunden.');
        $this->assertSame([], self::longLines(substr($dashboard, $start, $end - $start)));
    }

    /**
     * Die Seite hat kein Skript, das die Tabellen-Engine umschaltet. Die Tabellen
     * stapeln sich deshalb über eine eigene Regel, die die data-label-Attribute als
     * Zeilenbeschriftung zeigt.
     */
    public function testStorageTablesStackOnSmallScreens(): void
    {
        $css = self::read('public/css/style.css');

        $this->assertMatchesRegularExpression(
            '~@media \(max-width: 575\.98px\) \{[^@]*\.storage-table td::before \{[^}]*content: attr\(data-label\);~s',
            $css
        );
        $this->assertStringContainsString(
            'class="table table-sm align-middle storage-table"',
            self::read('templates/storage/index.twig')
        );
    }
}
