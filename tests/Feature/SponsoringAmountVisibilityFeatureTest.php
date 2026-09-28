<?php

declare(strict_types=1);

namespace Tests\Feature;

use PHPUnit\Framework\TestCase;

/**
 * Der Betrag einer Vereinbarung ist kein Allgemeingut.
 *
 * `SponsoringPolicy::canSeeSponsorshipDetails()` entscheidet darüber, und die
 * Sponsor-Detailseite reicht die Antwort je Vereinbarung als
 * `visible_details` ins Template. Die Tabelle hält sich daran und setzt sonst
 * einen Gedankenstrich.
 *
 * Die beiden Auswahlfelder "Vereinbarung" in den Kontakt-Modalen taten das
 * nicht: sie schrieben den Betrag in jeden Eintrag der Liste. Damit las eine
 * Beitragende im Modal "Kontakt protokollieren" genau die Zahlen, die eine
 * Bildschirmhöhe darüber als "Nur für die Sponsoring-Verwaltung sichtbar"
 * verdeckt waren - die Liste der Vereinbarungen ist dort ungefiltert, weil ein
 * Kontakt an jede von ihnen gehängt werden darf.
 *
 * Diese Prüfung liest das Template strukturell statt nach Textstellen: jede
 * Ausgabe von `sp.amount` muss entweder hinter einem Sichtbarkeits-Kennzeichen
 * stehen oder in einer Schleife, deren Liste bereits von der Policy gefiltert
 * wurde.
 */
class SponsoringAmountVisibilityFeatureTest extends TestCase
{
    /**
     * Listen, die der Controller bereits über die Policy gefiltert hat. Wer
     * eine Vereinbarung daraus in der Hand hat, darf sie auch ändern - und
     * damit ihren Betrag sehen.
     */
    private const PREFILTERED_LOOP_SOURCES = ['editable_sponsorships'];

    /** Kennzeichen, die im Template für "darf den Betrag sehen" stehen. */
    private const VISIBILITY_TOKENS = ['visible_details[sp.id]', 'sees_sp'];

    public function testJederBetragImSponsorDetailStehtHinterEinemSichtbarkeitsKennzeichen(): void
    {
        $path = dirname(__DIR__, 2) . '/templates/sponsoring/sponsors/detail.twig';
        $template = file_get_contents($path);
        $this->assertIsString($template);

        $lines = explode("\n", $template);
        $unguarded = [];

        foreach ($lines as $index => $line) {
            if (!str_contains($line, 'sp.amount')) {
                continue;
            }

            if (!$this->isGuarded($lines, $index)) {
                $unguarded[] = ($index + 1) . ': ' . trim($line);
            }
        }

        $this->assertSame(
            [],
            $unguarded,
            'Diese Zeilen geben einen Betrag aus, ohne dass die Policy gefragt wurde.'
        );
    }

    /**
     * Sucht von der Fundstelle aus rückwärts die umgebende `{% for sp in ... %}`
     * Schleife. Bis dorthin muss entweder ein Sichtbarkeits-Kennzeichen stehen
     * oder die Schleife selbst über eine bereits gefilterte Liste laufen.
     *
     * @param array<int, string> $lines
     */
    private function isGuarded(array $lines, int $usageIndex): bool
    {
        for ($index = $usageIndex; $index >= 0; $index--) {
            $line = $lines[$index];

            foreach (self::VISIBILITY_TOKENS as $token) {
                if (str_contains($line, $token)) {
                    return true;
                }
            }

            if (preg_match('/{%-?\s*for\s+sp\s+in\s+([A-Za-z0-9_.\[\]]+)/', $line, $match) !== 1) {
                continue;
            }

            return in_array($match[1], self::PREFILTERED_LOOP_SOURCES, true);
        }

        return false;
    }
}
