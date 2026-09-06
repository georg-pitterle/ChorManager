<?php

declare(strict_types=1);

namespace App\Util;

/**
 * Sortiert eine nach Namen geschlüsselte Liste in eine vorgegebene Reihenfolge.
 *
 * Die Reihenfolge kommt als Liste von Namen von aussen - üblicherweise aus der
 * Datenbank, damit eine Gruppierung genauso sortiert ist wie jede andere
 * Auflistung derselben Daten. Namen, die in der Vorgabe fehlen, hängen
 * alphabetisch hinten an; ausgewiesene Sammelschlüssel ("ohne Stimmgruppe",
 * "ohne Teilstimme") stehen immer ganz am Schluss.
 *
 * Die Klasse selbst greift nicht auf die Datenbank zu - das machen die Aufrufer
 * VoiceGroupOrder und SubVoiceOrder, die je einmal die Namensreihenfolge holen.
 */
final class NameKeyedOrder
{
    /**
     * @param array<array-key, mixed> $map
     * @param array<int, string> $rankedNames Namen in der gewünschten Reihenfolge
     * @param list<string> $trailingKeys Schlüssel, die nach allen anderen kommen
     * @return array<array-key, mixed>
     */
    public static function sort(array $map, array $rankedNames, array $trailingKeys = []): array
    {
        $rank = array_flip($rankedNames);

        uksort($map, static function ($a, $b) use ($rank, $trailingKeys): int {
            // Ein rein numerischer Name wird von PHP zum Integer-Schlüssel und käme
            // hier als int an; verglichen wird deshalb immer die Zeichenkette.
            $a = (string) $a;
            $b = (string) $b;

            $aTrailing = in_array($a, $trailingKeys, true);
            $bTrailing = in_array($b, $trailingKeys, true);
            if ($aTrailing !== $bTrailing) {
                return $aTrailing ? 1 : -1;
            }

            $rankA = $rank[$a] ?? PHP_INT_MAX;
            $rankB = $rank[$b] ?? PHP_INT_MAX;
            if ($rankA === $rankB) {
                return strcmp($a, $b);
            }

            return $rankA <=> $rankB;
        });

        return $map;
    }
}
