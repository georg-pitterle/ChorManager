<?php

declare(strict_types=1);

namespace App\Util;

use App\Models\SubVoice;

/**
 * Zentrale Stelle für die projektweite Reihenfolge der Teilstimmen: sie folgt dem
 * Namen, sortiert von der Datenbank (`sub_voices.name`, Kollation
 * utf8mb4_general_ci - Gross/Klein spielt keine Rolle, Umlaute stehen bei ihrem
 * Grundbuchstaben). Genauso liefert die Beziehung `User::subVoices()` ihre Werte.
 *
 * Gruppierungen, die in PHP entstehen, sortierten die Teilstimmen vorher per
 * ksort() nach Bytefolge. Das stellte Grossbuchstaben vor Kleinbuchstaben und
 * Umlaute hinter das Z - dieselben Teilstimmen standen damit je nach Seite in
 * unterschiedlicher Reihenfolge.
 */
final class SubVoiceOrder
{
    /**
     * Sortiert die Teilstimmen-Ebene einer nach Stimmgruppe und Teilstimme
     * geschachtelten Auflistung.
     *
     * Die Namensreihenfolge wird einmal geladen und nicht je Stimmgruppe erneut -
     * ein Aufruf je Stimmgruppe wäre eine Abfrage je Stimmgruppe.
     *
     * @param array<array-key, array<array-key, mixed>> $groupedByVoiceGroup
     * @param list<string> $trailingKeys Teilstimmen-Schlüssel, die zuletzt kommen
     * @return array<array-key, array<array-key, mixed>>
     */
    public static function sortNestedSubVoiceLevel(array $groupedByVoiceGroup, array $trailingKeys = []): array
    {
        $rankedNames = SubVoice::orderBy('name')->pluck('name')->all();

        foreach ($groupedByVoiceGroup as $voiceGroup => $subVoices) {
            $groupedByVoiceGroup[$voiceGroup] = NameKeyedOrder::sort($subVoices, $rankedNames, $trailingKeys);
        }

        return $groupedByVoiceGroup;
    }
}
