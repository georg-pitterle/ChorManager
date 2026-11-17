<?php

declare(strict_types=1);

namespace App\Util;

use Carbon\CarbonImmutable;

/**
 * Die Eingabe einer Terminserie: Takt, Intervall, Wochentage, Enddatum und die
 * Feldgruppen einer Serienänderung.
 *
 * Diese Regeln standen als sechs statische Methoden samt drei Konstanten im
 * EventController. Sie entscheiden nichts über HTTP und brauchen keine
 * Datenbank - sie lesen ein Formular und sagen, was davon eine Serie sein
 * kann. Herausgezogen mit der Antwort auf Review-Lauf 77 (Frage 2b), damit der
 * Serienteil an einer Stelle steht und ohne Controller prüfbar ist.
 *
 * Alle Methoden antworten mit `null` auf eine unbrauchbare Angabe. Das ist
 * Absicht: Ein stillschweigend zurechtgebogener Wert wäre eine Serie, die
 * niemand so bestellt hat.
 */
final class EventSeriesInput
{
    /**
     * Zugelassene Takte einer Terminserie. Ein unbekannter Takt darf nicht bis in
     * die Terminerzeugung durchkommen - EventRecurrenceService kennt ihn nicht und
     * würde ihn als Wochentakt auslegen.
     */
    public const FREQUENCIES = ['daily', 'weekly', 'monthly', 'yearly'];

    /**
     * Feldgruppen, die eine Serienänderung auf die Folgetermine überträgt. Ohne
     * Auswahl gelten alle - so verhält sich die Serienänderung wie bisher, wer
     * einzelne Termine angepasst hat, kann sie aber gezielt aussparen.
     */
    public const FIELD_GROUPS = ['title', 'location', 'time', 'registration', 'attendance', 'audience'];

    /** Beschriftungen derselben Feldgruppen für das Bearbeiten-Formular. */
    public const FIELD_GROUP_LABELS = [
        'title' => 'Titel und Terminart',
        'location' => 'Ort',
        'time' => 'Uhrzeit',
        'registration' => 'Anmeldung und Anmeldeschluss',
        'attendance' => 'Anwesenheitspflicht',
        'audience' => 'Zielgruppe',
    ];

    /**
     * Liefert den Takt einer Serie oder null, wenn der Wert nicht zu den vier
     * unterstützten Takten gehört. Ein fehlendes Feld gilt weiterhin als
     * "wöchentlich", ein gesetzter, aber unbekannter Wert nicht.
     */
    public static function frequency(mixed $value): ?string
    {
        if ($value === null) {
            return 'weekly';
        }

        $candidate = strtolower(trim(InputValidator::asString($value)));

        return in_array($candidate, self::FREQUENCIES, true) ? $candidate : null;
    }

    /**
     * Liefert das Wiederholungsintervall oder null bei einer ungültigen Angabe.
     * Ein leeres Feld bleibt beim bisherigen Standardwert 1; 0 oder weniger ist
     * kein Intervall, sondern ein Stillstand der Erzeugungsschleife.
     */
    public static function interval(mixed $value): ?int
    {
        $candidate = trim(InputValidator::asString($value));
        if ($candidate === '') {
            return 1;
        }

        return ctype_digit($candidate) && (int) $candidate >= 1 ? (int) $candidate : null;
    }

    /**
     * Reduziert die gewählten Wochentage auf gültige ISO-Nummern (1 = Mo bis 7 = So).
     * Ein unbekannter Wert würde sonst als Text in der Serie landen und auf keinen
     * Kalendertag passen.
     *
     * @param array<array-key, mixed> $value
     * @return list<int>
     */
    public static function weekdays(array $value): array
    {
        $weekdays = [];
        foreach ($value as $day) {
            $candidate = trim((string) $day);
            if (ctype_digit($candidate) && (int) $candidate >= 1 && (int) $candidate <= 7) {
                $weekdays[] = (int) $candidate;
            }
        }

        return array_values(array_unique($weekdays));
    }

    /**
     * Liest das Enddatum einer Serie oder liefert null, wenn nichts Lesbares
     * ankam. Carbon nimmt fast alles entgegen und wirft beim Rest eine Ausnahme
     * mit Zeitzonen-Innenleben im Text ("The timezone could not be found in the
     * database") - die hatte im Formular nichts verloren.
     */
    public static function endDate(mixed $value): ?CarbonImmutable
    {
        $candidate = trim(InputValidator::asString($value));
        if ($candidate === '') {
            return null;
        }

        try {
            return CarbonImmutable::parse($candidate);
        } catch (\Throwable) {
            return null;
        }
    }

    /**
     * Feldgruppen, die eine Serienänderung überträgt. Fehlt die Angabe ganz -
     * etwa aus einem älteren Formular -, gelten alle: das ist das bisherige
     * Verhalten und die einzige Auslegung, die keine Änderung verschluckt.
     *
     * @return list<string>
     */
    public static function fieldGroups(mixed $value): array
    {
        if (!is_array($value)) {
            return self::FIELD_GROUPS;
        }

        $selected = [];
        foreach ($value as $candidate) {
            $group = strtolower(trim(InputValidator::asString($candidate)));
            if (in_array($group, self::FIELD_GROUPS, true)) {
                $selected[$group] = true;
            }
        }

        return array_values(array_keys($selected));
    }

    /**
     * Feldgruppen mit Beschriftung für das Formular.
     *
     * @return list<array{key: string, label: string}>
     */
    public static function fieldGroupOptions(): array
    {
        $options = [];
        foreach (self::FIELD_GROUPS as $group) {
            $options[] = ['key' => $group, 'label' => self::FIELD_GROUP_LABELS[$group]];
        }

        return $options;
    }
}
