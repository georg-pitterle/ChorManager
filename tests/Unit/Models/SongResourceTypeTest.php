<?php

declare(strict_types=1);

namespace Tests\Unit\Models;

use App\Models\Song;
use App\Models\SongResource;
use PHPUnit\Framework\TestCase;

/**
 * Die Art einer Liedquelle trägt einen Namen, keine verstreute Zeichenkette.
 *
 * Dass die Werte zum ENUM der Spalte passen, hält ModelSchemaConsistencyTest
 * fest, seit 20261029120000 aus dem `varchar(32)` ein ENUM gemacht hat. Hier
 * steht das, was daneben gilt: dass die Art ein Bezeichner ist, dass Filter und
 * Sortierung in der Beziehung stehen, und dass niemand den Wert wieder
 * ausschreibt - gleiche Aufteilung wie zwischen TaskStatusValuesTest und dem
 * Schema-Wächter.
 *
 * Solange die Spalte ein `varchar(32)` war, gab es keine Grenze, gegen die ein
 * Wert hätte stehen können, und beide Richtungen schwiegen. Ein Vertipper in einer Schreibstelle
 * (SongLibraryController::storeLinkResource) legt eine Quelle an, die keine
 * Leseseite je findet; einer in `Song::linkResources()` liefert eine leere
 * Liste. Der Wert stand an sechs Stellen als `'link'` da - im Modell, dreimal im
 * SongLibraryController, im DownloadController und im Seed.
 */
final class SongResourceTypeTest extends TestCase
{
    public function testDieArtenSindEnglischeBezeichner(): void
    {
        self::assertSame(['link'], SongResource::RESOURCE_TYPES);

        foreach (SongResource::RESOURCE_TYPES as $type) {
            self::assertMatchesRegularExpression('/^[a-z][a-z0-9_]*$/', $type);
        }
    }

    public function testDieArtPasstInDieSpalte(): void
    {
        foreach (SongResource::RESOURCE_TYPES as $type) {
            self::assertLessThanOrEqual(32, strlen($type), 'resource_type ist ein varchar(32).');
        }
    }

    /**
     * Filter und Sortierung der Links stehen in der Beziehung selbst.
     *
     * Vorher brachten SongLibraryController und DownloadController beim
     * Vorabladen je einen eigenen Verschluss mit, der `where('resource_type',
     * 'link')->orderBy('title')` wortgleich wiederholte. Das war nicht nur
     * doppelt, es hätte eine Änderung an der Beziehung auch still überstimmt -
     * und zwei Feature-Tests hielten den Wortlaut dieser Verschlüsse fest.
     */
    public function testDieBeziehungBringtFilterUndSortierungSelbstMit(): void
    {
        $sql = (new Song())->linkResources()->toSql();

        self::assertStringContainsString('`resource_type` = ?', $sql);
        self::assertStringContainsString('order by `title` asc', $sql);
        self::assertSame(
            [SongResource::TYPE_LINK],
            array_values(array_filter(
                (new Song())->linkResources()->getBindings(),
                static fn (mixed $binding): bool => is_string($binding)
            ))
        );
    }

    /**
     * Gelesen wird die Quelle, weil genau die Schreibweise gemeint ist: Eine
     * Reflexion sähe die Zeichenkette im Methodenrumpf nicht.
     */
    public function testNiemandSchreibtDieArtNochAlsZeichenkette(): void
    {
        $root = dirname(__DIR__, 3);
        $files = array_merge(
            glob($root . '/src/Models/*.php') ?: [],
            glob($root . '/src/Controllers/*.php') ?: [],
            glob($root . '/src/Services/*.php') ?: []
        );

        self::assertGreaterThan(50, count($files), 'Es wurden kaum Quelldateien gefunden.');

        $problems = [];

        foreach ($files as $file) {
            $source = (string) file_get_contents($file);

            if (preg_match("/'resource_type'\s*=>\s*'/", $source) === 1) {
                $problems[] = basename($file) . ": schreibt resource_type als Zeichenkette.";
            }

            if (preg_match("/where\(\s*'resource_type'\s*,\s*'/", $source) === 1) {
                $problems[] = basename($file) . ": filtert resource_type mit einer Zeichenkette.";
            }

            if (preg_match("/resource_type\s*!==?\s*'/", $source) === 1) {
                $problems[] = basename($file) . ": vergleicht resource_type mit einer Zeichenkette.";
            }
        }

        self::assertSame([], $problems, implode("\n", $problems));
    }
}
