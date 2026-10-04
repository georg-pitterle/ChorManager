<?php

declare(strict_types=1);

namespace App\Services\Audience;

use App\Models\AudienceFilterCondition as C;
use Illuminate\Database\Capsule\Manager as DB;

/**
 * Prüft Formularwerte einer Freigabe-Zeile. Nur existierende Kennungen bleiben;
 * eine Zeile ohne Bedingung ist nur mit ausdrücklichem "Alle Mitglieder"
 * gültig - sonst würde eine halb ausgefüllte Zeile den ganzen Chor freigeben.
 * Eine Kategorie, deren Werte alle verschwunden sind, wird abgelehnt statt
 * entfernt: Der Filter würde sonst weiter statt enger.
 */
final class AudienceFilterNormalizer
{
    private const TABLES = [
        C::CATEGORY_ROLE => 'roles',
        C::CATEGORY_VOICE_GROUP => 'voice_groups',
        C::CATEGORY_SUB_VOICE => 'sub_voices',
        C::CATEGORY_PROJECT => 'projects',
        C::CATEGORY_USER => 'users',
    ];

    private const LABELS = [
        C::CATEGORY_ROLE => 'Rolle',
        C::CATEGORY_VOICE_GROUP => 'Stimmgruppe',
        C::CATEGORY_SUB_VOICE => 'Untergruppe',
        C::CATEGORY_PROJECT => 'Projekt',
        C::CATEGORY_USER => 'Mitglied',
    ];

    /**
     * @param array<string, mixed> $raw Zeile mit all und conditions
     * @return array<string, list<int>>
     */
    public function normalize(array $raw): array
    {
        if (!empty($raw['all'])) {
            return [];
        }

        $conditions = [];
        $input = is_array($raw['conditions'] ?? null) ? $raw['conditions'] : [];
        foreach (C::CATEGORIES as $category) {
            $values = $input[$category] ?? [];
            if (!is_array($values)) {
                continue;
            }
            $ids = [];
            foreach ($values as $value) {
                if (is_scalar($value) && ctype_digit((string) $value) && (int) $value > 0) {
                    $ids[] = (int) $value;
                }
            }
            $ids = array_values(array_unique($ids));
            if ($ids === []) {
                continue;
            }
            $existing = DB::table(self::TABLES[$category])->whereIn('id', $ids)->pluck('id')
                ->map(fn ($id): int => (int) $id)->all();
            sort($existing);
            if ($existing === []) {
                // Fiele die Kategorie still weg, träfe der Rest-Filter mehr Mitglieder
                // als vorher. Ein verschwundener Wert neben einem gültigen fällt
                // dagegen weg, ohne etwas zu öffnen - er traf ohnehin niemanden.
                throw new InvalidAudienceFilterException(sprintf(
                    '%s: Die gewählten Einträge gibt es nicht mehr. Bitte entfernen oder ersetzen.',
                    self::LABELS[$category]
                ));
            }
            $conditions[$category] = $existing;
        }

        if ($conditions === []) {
            throw new InvalidAudienceFilterException(
                'Bitte mindestens eine Bedingung wählen oder "Alle Mitglieder" ankreuzen.'
            );
        }

        return $conditions;
    }

    /**
     * Mehrere Zeilen eines Formulars. Gleiche Bedingungsmengen werden zu einer
     * zusammengelegt; eine ungültige Zeile lehnt das ganze Formular ab.
     *
     * @param array<int, mixed> $rows
     * @return list<array<string, list<int>>>
     */
    public function normalizeRows(array $rows): array
    {
        $bySignature = [];
        foreach ($rows as $row) {
            if (!is_array($row)) {
                continue;
            }
            $conditions = $this->normalize($row);
            $bySignature[$this->signature($conditions)] ??= $conditions;
        }

        return array_values($bySignature);
    }

    /**
     * Gleiche Bedingungsmengen ergeben dieselbe Signatur, unabhängig von der
     * Reihenfolge.
     *
     * @param array<string, list<int>> $conditions
     */
    public function signature(array $conditions): string
    {
        $parts = [];
        foreach (C::CATEGORIES as $category) {
            if (!empty($conditions[$category])) {
                $ids = $conditions[$category];
                sort($ids);
                $parts[] = $category . '=' . implode(',', $ids);
            }
        }

        return implode(';', $parts);
    }
}
