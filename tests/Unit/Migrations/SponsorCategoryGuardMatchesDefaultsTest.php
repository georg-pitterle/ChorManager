<?php

declare(strict_types=1);

namespace Tests\Unit\Migrations;

use AddSponsorCategories;
use PHPUnit\Framework\TestCase;

/**
 * 20261023120000 trägt die Vorgabe-Kategorien zweimal: einmal als
 * `DEFAULT_CATEGORIES`, aus der das `up()` die Zeilen einsetzt, und einmal
 * wortgleich als Literal in der `NOT IN (...)`-Liste von
 * `USER_OWNED_CATEGORIES_SQL`. Die Zählabfrage im `down()` lässt damit genau
 * die Vorgaben aus der Prüfung heraus und schlägt nur noch auf Kategorien an,
 * die im Betrieb dazukamen.
 *
 * Die Doppelung ist nicht zu vermeiden: Eine `const` darf in PHP keinen
 * Funktionsaufruf enthalten, die Liste lässt sich dort also nicht in SQL
 * zusammensetzen - und als `public const` muss die Abfrage stehen, damit sich
 * genau die Anweisung prüfen lässt, die auch läuft (`/phinx-migration`).
 *
 * Sie ist aber geräuschlos kaputt zu machen: Wer eine Vorgabe umbenennt,
 * hinzufügt oder entfernt und nur die Konstante anfasst, hinterlässt eine
 * Zählabfrage auf dem alten Stand. Die zählt dann die neue Vorgabe als
 * "selbst angelegt" mit und blockiert den Rückbau einer frischen Installation
 * - oder lässt umgekehrt eine entfernte Vorgabe ungezählt durch. Auffallen
 * würde das erst beim Rückbau, also lange nach der Änderung.
 *
 * Der Test bindet beide Seiten aneinander und braucht keine Datenbank.
 */
final class SponsorCategoryGuardMatchesDefaultsTest extends TestCase
{
    private const MIGRATION_FILE = __DIR__
        . '/../../../db/migrations/20261023120000_add_sponsor_categories.php';

    protected function setUp(): void
    {
        require_once self::MIGRATION_FILE;
    }

    public function testGuardListsExactlyTheDefaultCategories(): void
    {
        $expected = AddSponsorCategories::DEFAULT_CATEGORIES;
        sort($expected);

        $actual = $this->namesInGuard();
        sort($actual);

        $this->assertSame(
            $expected,
            $actual,
            "Die NOT-IN-Liste in USER_OWNED_CATEGORIES_SQL weicht von DEFAULT_CATEGORIES ab.\n"
                . 'Beide Seiten müssen dieselben Namen tragen, sonst zählt der Rückbau eine Vorgabe '
                . 'als selbst angelegte Kategorie mit - oder lässt eine entfallene ungezählt durch.'
        );
    }

    /**
     * Die Namen aus der `NOT IN (...)`-Liste der Zählabfrage.
     *
     * @return list<string>
     */
    private function namesInGuard(): array
    {
        $sql = AddSponsorCategories::USER_OWNED_CATEGORIES_SQL;

        $start = strpos($sql, 'NOT IN (');
        self::assertNotFalse($start, 'USER_OWNED_CATEGORIES_SQL enthält keine NOT-IN-Liste mehr.');

        $end = strpos($sql, ')', $start);
        self::assertNotFalse($end, 'Die NOT-IN-Liste in USER_OWNED_CATEGORIES_SQL ist nicht geschlossen.');

        $list = substr($sql, $start, $end - $start);
        preg_match_all("/'([^']*)'/", $list, $matches);
        self::assertNotSame([], $matches[1], 'Die NOT-IN-Liste in USER_OWNED_CATEGORIES_SQL ist leer.');

        return $matches[1];
    }
}
