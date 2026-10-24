<?php

declare(strict_types=1);

namespace App\Util;

use App\Models\SponsorCategory;

/**
 * Macht aus der Eingabe im Sponsor-Formular eine Kategorie-Id.
 *
 * Das Feld nimmt einen Namen entgegen, keine Id: Wer eine vorhandene Kategorie
 * wählt und wer eine neue eintippt, schickt dasselbe. Eine vorhandene wird
 * ohne Rücksicht auf Groß- und Kleinschreibung wiederverwendet (das erledigt
 * die Kollation der Spalte), eine neue angelegt.
 */
final class SponsorCategoryResolver
{
    public const MAX_LENGTH = 100;
    public const LENGTH_ERROR = 'Eine Kategorie darf höchstens 100 Zeichen lang sein.';

    /**
     * @return array{error: ?string, id: ?int}
     */
    public static function resolve(mixed $input): array
    {
        $name = trim((string) preg_replace('/\s+/u', ' ', InputValidator::asString($input)));
        if ($name === '') {
            return ['error' => null, 'id' => null];
        }

        if (mb_strlen($name) > self::MAX_LENGTH) {
            return ['error' => self::LENGTH_ERROR, 'id' => null];
        }

        return ['error' => null, 'id' => (int) SponsorCategory::firstOrCreate(['name' => $name])->id];
    }
}
