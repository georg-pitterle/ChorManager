<?php

declare(strict_types=1);

namespace App\Util;

use App\Models\Sponsor;

/**
 * Sucht einen vorhandenen Sponsor, dessen Name dem neuen bis auf Schreibweise,
 * Satzzeichen und Rechtsform gleicht.
 *
 * Verglichen wird in PHP über alle Namen: Ein Chor hat Hunderte Sponsoren,
 * nicht Hunderttausende, und der Vergleichsschlüssel lässt sich in SQL nicht
 * ohne eigene Spalte nachbilden.
 */
final class SponsorDuplicateFinder
{
    public static function find(string $name, ?int $exceptId = null): ?Sponsor
    {
        $key = SponsorNameNormalizer::normalize($name);
        if ($key === '') {
            return null;
        }

        $query = Sponsor::query()->select(['id', 'name'])->orderBy('id');
        if ($exceptId !== null) {
            $query->where('id', '<>', $exceptId);
        }

        foreach ($query->cursor() as $sponsor) {
            if (SponsorNameNormalizer::normalize((string) $sponsor->name) === $key) {
                return $sponsor;
            }
        }

        return null;
    }
}
