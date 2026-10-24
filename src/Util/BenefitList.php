<?php

declare(strict_types=1);

namespace App\Util;

/**
 * Gegenleistungen als Liste aus einem Textfeld: eine Leistung pro Zeile.
 *
 * Paket und Vereinbarung nehmen ihre Leistungen auf demselben Weg entgegen.
 * Ein Textfeld statt einer Zeilentabelle mit Plus- und Minusknöpfen, weil die
 * Liste meist aus einem Angebotsschreiben herauskopiert wird - und dann ist
 * Einfügen der schnellste Weg.
 */
final class BenefitList
{
    public const MAX_LENGTH = 255;
    public const MAX_ITEMS = 30;

    public const LENGTH_ERROR = 'Eine Gegenleistung darf höchstens 255 Zeichen lang sein.';
    public const COUNT_ERROR = 'Bitte höchstens 30 Gegenleistungen je Eintrag erfassen.';
    public const FORMAT_ERROR = 'Die Gegenleistungen bitte als Text eintragen, eine pro Zeile.';

    /**
     * Wie die Liste in den Meldungen heißt. Die Leistungen des Sponsors nehmen
     * ihre Zeilen auf demselben Weg entgegen wie die Gegenleistungen; nur die
     * Meldung soll sagen, welches Feld gemeint ist.
     */
    public const BENEFIT_LABELS = ['singular' => 'Gegenleistung', 'plural' => 'Gegenleistungen'];
    public const DELIVERABLE_LABELS = ['singular' => 'Leistung des Sponsors', 'plural' => 'Leistungen des Sponsors'];

    /**
     * Zerlegt die Eingabe in Leistungen. Leere Zeilen fallen weg, Leerraum am
     * Rand ebenso; null heißt "nicht übermittelt" und bleibt null.
     *
     * @param array{singular: string, plural: string} $labels
     * @return array{error: string|null, items: list<string>|null}
     */
    public static function parse(mixed $input, array $labels = self::BENEFIT_LABELS): array
    {
        if ($input === null) {
            return ['error' => null, 'items' => null];
        }

        // Ein Array (benefits[]=...) kommt aus keinem Formular. Still als leere
        // Liste gelesen, löschte es alle vorhandenen Leistungen.
        if (!is_string($input)) {
            return ['error' => sprintf('Die %s bitte als Text eintragen, eine pro Zeile.', $labels['plural']), 'items' => null];
        }

        $lines = preg_split('/\R/u', InputValidator::asString($input)) ?: [];
        $items = [];
        foreach ($lines as $line) {
            $line = trim($line);
            if ($line === '') {
                continue;
            }
            if (mb_strlen($line) > self::MAX_LENGTH) {
                return [
                    'error' => sprintf('Eine %s darf höchstens %d Zeichen lang sein.', $labels['singular'], self::MAX_LENGTH),
                    'items' => null,
                ];
            }
            $items[] = $line;
        }

        if (count($items) > self::MAX_ITEMS) {
            return [
                'error' => sprintf('Bitte höchstens %d %s je Eintrag erfassen.', self::MAX_ITEMS, $labels['plural']),
                'items' => null,
            ];
        }

        return ['error' => null, 'items' => $items];
    }
}
