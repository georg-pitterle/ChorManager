<?php

declare(strict_types=1);

namespace App\Util;

use App\Models\VoiceGroup;

/**
 * Central helper for the project-wide voice-group ordering convention:
 * every listing/iteration over voice groups is ordered Sopran, Alt, Tenor,
 * Bass (canonical seed id order). Groups not present in the seed order are
 * appended alphabetically; designated trailing keys (e.g. "Ohne Stimmgruppe")
 * always come last.
 */
final class VoiceGroupOrder
{
    /**
     * Reorders an associative array keyed by voice-group NAME into canonical
     * SATB order without touching the values.
     *
     * @param array<string, mixed> $map
     * @param list<string> $trailingKeys keys that must sort after every group
     * @return array<string, mixed>
     */
    public static function sortNameKeyedMap(array $map, array $trailingKeys = []): array
    {
        // name => 0-based rank in id order (Sopran, Alt, Tenor, Bass, ...)
        $rank = array_flip(VoiceGroup::orderBy('id')->pluck('name')->all());

        uksort($map, static function (string $a, string $b) use ($rank, $trailingKeys): int {
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
