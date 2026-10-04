<?php

declare(strict_types=1);

namespace App\Services\Audience;

/**
 * Zielgruppen-Zeilen aus einem Formular: Stufe (nur Freigaben), "Alle
 * Mitglieder", Bedingungen je Kategorie. Nur die Form wird hier geprüft, die
 * Werte prüft AudienceFilterNormalizer.
 */
final class AudienceFormInput
{
    /**
     * @return list<array{level: int, all: bool, conditions: array<string, list<string>>}>
     */
    public static function rows(mixed $raw): array
    {
        if (!is_array($raw)) {
            return [];
        }

        $rows = [];
        foreach ($raw as $row) {
            if (!is_array($row)) {
                continue;
            }
            $conditions = [];
            foreach (is_array($row['conditions'] ?? null) ? $row['conditions'] : [] as $category => $values) {
                if (is_string($category) && is_array($values)) {
                    $conditions[$category] = array_values(array_map('strval', array_filter($values, 'is_scalar')));
                }
            }
            $rows[] = [
                'level' => (int) ($row['level'] ?? 0),
                'all' => !empty($row['all']),
                'conditions' => $conditions,
            ];
        }

        return $rows;
    }
}
