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
        // Namen in Kennungs-Reihenfolge (Sopran, Alt, Tenor, Bass, ...); das
        // Einsortieren selbst macht NameKeyedOrder, gemeinsam mit SubVoiceOrder.
        return NameKeyedOrder::sort($map, VoiceGroup::orderBy('id')->pluck('name')->all(), $trailingKeys);
    }
}
