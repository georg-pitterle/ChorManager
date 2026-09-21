<?php

declare(strict_types=1);

namespace Tests\Feature;

use PHPUnit\Framework\TestCase;

/**
 * Jede Datei unter /vendor/, die ein Template einbindet, muss bin/copy-assets.php
 * auch nach public/vendor/ legen.
 *
 * Anlass: FullCalendar 7 hat seine fünf Pakete zu einem zusammengelegt und die
 * Dateinamen geändert. Bleibt dabei ein Template auf dem alten Pfad stehen, liefert
 * der Server 404, der Kalender bleibt leer - und keine PHP-Prüfung merkt es, weil
 * die Seite selbst mit 200 antwortet.
 *
 * Der Test liest den Stand nach dem letzten `composer install` bzw. `copy-assets`;
 * public/vendor/ ist nicht eingecheckt.
 */
final class VendorAssetReferencesFeatureTest extends TestCase
{
    public function testEveryVendorAssetReferencedByATemplateExists(): void
    {
        $root = dirname(__DIR__, 2);
        $references = [];

        $templates = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($root . '/templates', \FilesystemIterator::SKIP_DOTS)
        );
        foreach ($templates as $file) {
            if (!$file instanceof \SplFileInfo || $file->getExtension() !== 'twig') {
                continue;
            }

            $template = (string) file_get_contents($file->getPathname());
            preg_match_all('#(?:src|href)="(/vendor/[^"?\#{]+)"#', $template, $m);
            foreach ($m[1] as $path) {
                $references[$path][] = substr($file->getPathname(), strlen($root) + 1);
            }
        }

        $this->assertNotEmpty($references, 'Keine /vendor/-Einbindung gefunden - das Muster greift nicht mehr.');

        $missing = [];
        foreach ($references as $path => $usedIn) {
            if (!is_file($root . '/public' . $path)) {
                $missing[] = $path . ' (in ' . implode(', ', array_unique($usedIn)) . ')';
            }
        }

        $this->assertSame([], $missing, 'Eingebunden, aber von bin/copy-assets.php nicht bereitgestellt.');
    }
}
