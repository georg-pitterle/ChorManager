<?php

declare(strict_types=1);

namespace Tests\Feature;

use PHPUnit\Framework\TestCase;

/**
 * Tabellen müssen am Telefon lesbar sein. Breite Tabellen laufen über die Table-Engine,
 * die bei Platzmangel auf Karten umschaltet: Container mit data-table-engine, Tabelle mit
 * table-responsive-cards, Zellen mit data-label als Beschriftung der Karte.
 *
 * Ausgenommen sind Tabellen mit höchstens zwei Spalten (passen auch schmal) und Tabellen
 * mit eigener Mobilansicht (storage-table, siehe public/css/style.css).
 */
class UiTablesFeatureTest extends TestCase
{
    /** Tabellen mit eigener Mobilansicht statt Engine. */
    private const OWN_MOBILE_VIEW = ['storage-table'];

    /**
     * @return list<array{path: string, line: int, open: string, body: string, before: string}>
     */
    private static function tables(): array
    {
        $root = dirname(__DIR__, 2);
        $tables = [];
        $iterator = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($root . '/templates', \FilesystemIterator::SKIP_DOTS)
        );

        foreach ($iterator as $file) {
            if (!$file instanceof \SplFileInfo || $file->getExtension() !== 'twig') {
                continue;
            }

            $relative = str_replace(DIRECTORY_SEPARATOR, '/', substr($file->getPathname(), strlen($root) + 1));
            if (str_starts_with($relative, 'templates/emails/')) {
                continue;
            }

            $content = (string) file_get_contents($file->getPathname());
            preg_match_all('/<table\b[^>]*>/', $content, $matches, PREG_OFFSET_CAPTURE);

            foreach ($matches[0] as [$open, $offset]) {
                $offset = (int) $offset;
                $end = strpos($content, '</table>', $offset);
                $tables[] = [
                    'path' => $relative,
                    'line' => substr_count(substr($content, 0, $offset), "\n") + 1,
                    'open' => $open,
                    'body' => substr($content, $offset, ($end === false ? strlen($content) : $end) - $offset),
                    'before' => substr($content, max(0, $offset - 2500), min(2500, $offset)),
                ];
            }
        }

        return $tables;
    }

    private static function columnCount(string $body): int
    {
        if (preg_match('/<thead\b.*?<\/thead>/s', $body, $head) === 1) {
            return preg_match_all('/<th\b/', $head[0]);
        }

        return 0;
    }

    public function testWideTablesUseTheTableEngine(): void
    {
        $offenders = [];

        foreach (self::tables() as $table) {
            $ownView = false;
            foreach (self::OWN_MOBILE_VIEW as $class) {
                $ownView = $ownView || str_contains($table['open'], $class);
            }
            if ($ownView || self::columnCount($table['body']) <= 2) {
                continue;
            }

            $where = $table['path'] . ':' . $table['line'];
            if (!str_contains($table['before'], 'data-table-engine="true"')) {
                $offenders[] = $where . ' ohne data-table-engine';
            }
            if (!str_contains($table['open'], 'table-responsive-cards')) {
                $offenders[] = $where . ' ohne table-responsive-cards';
            }
        }

        $this->assertSame([], $offenders, 'Breite Tabellen ohne Table-Engine.');
    }

    /**
     * Ohne data-label zeigt die Kartenansicht nackte Werte. Jede Zelle einer Engine-Tabelle
     * trägt eine Beschriftung - außer reinen Aktionszellen und Zeilen über die volle Breite.
     */
    public function testEngineTableCellsCarryLabels(): void
    {
        $offenders = [];

        foreach (self::tables() as $table) {
            if (!str_contains($table['open'], 'table-responsive-cards')) {
                continue;
            }

            preg_match_all('/<td\b([^>]*)>/', $table['body'], $cells);
            foreach ($cells[1] as $attributes) {
                if (str_contains($attributes, 'data-label') || str_contains($attributes, 'colspan')) {
                    continue;
                }
                if (str_contains($attributes, 'text-end')) {
                    continue; // Aktionsspalte ohne Beschriftung
                }

                $offenders[] = $table['path'] . ':' . $table['line'] . ' <td' . $attributes . '>';
            }
        }

        $this->assertSame([], $offenders, 'Zellen ohne data-label in Engine-Tabellen.');
    }

    /**
     * Ohne Werkzeugleiste gibt es keine Seitenknöpfe. Die Engine teilt trotzdem in Seiten
     * (Standard 100 Zeilen) - eine Untertabelle ohne Leiste muss deshalb so groß eingestellt
     * sein, dass nie eine Zeile still verschwindet (z. B. ein langer Kontoauszug im Import).
     */
    public function testEngineTablesWithoutToolbarNeverHideRows(): void
    {
        $root = dirname(__DIR__, 2);
        $offenders = [];

        foreach (glob($root . '/templates/{,*/,*/*/}*.twig', GLOB_BRACE) ?: [] as $file) {
            $content = (string) file_get_contents($file);
            preg_match_all('/<div\b[^>]*data-table-engine="true"[^>]*>/s', $content, $containers, PREG_OFFSET_CAPTURE);

            foreach ($containers[0] as [$open, $offset]) {
                $offset = (int) $offset;
                $tableStart = strpos($content, '<table', $offset);
                $next = substr($content, $offset, ($tableStart === false ? strlen($content) : $tableStart) - $offset);
                if (str_contains($next, 'table_toolbar.twig')) {
                    continue;
                }
                if (preg_match('/data-default-page-size="(\d+)"/', $open, $size) !== 1 || (int) $size[1] < 1000) {
                    $offenders[] = str_replace($root . DIRECTORY_SEPARATOR, '', $file) . ': ' . trim(preg_replace('/\s+/', ' ', $open) ?? '');
                }
            }
        }

        $this->assertSame([], $offenders, 'Engine-Tabellen ohne Werkzeugleiste mit Seitenaufteilung.');
    }

    /**
     * Summenzeilen stehen im tfoot. In der Kartenansicht muss auch er zum Block werden, sonst
     * schrumpft die Karte "Gesamt" auf Inhaltsbreite (Kassabericht am Telefon).
     */
    public function testFooterRowsBecomeFullWidthCards(): void
    {
        $css = (string) file_get_contents(dirname(__DIR__, 2) . '/public/css/table-engine.css');

        $this->assertMatchesRegularExpression(
            '/\.table-responsive-cards tfoot,[^{]*\{[^}]*display: block;[^}]*width: 100%;/s',
            $css
        );
    }
}
