<?php

declare(strict_types=1);

namespace App\Util;

/**
 * Projektdaten und Logo-Fristen aus dem Formular: gültiges Datum, leer oder abgelehnt.
 *
 * Die Regeln standen im ProjectController, und der SponsoringDashboardController
 * rief sie von dort, damit dieselbe Regel an beiden Stellen gilt.
 */
final class ProjectDateInput
{
    public const INVALID_LOGO_DEADLINE_MESSAGE = 'Die Logo-Fristen müssen ein Datum sein (JJJJ-MM-TT) oder leer bleiben.';

    /**
     * Beginn und Ende eines Projekts aus dem Formular: `null` für "nicht
     * angegeben", `false` für "kein Datum".
     *
     * Vorher ging der Wert ungeprüft in `$project->start_date`, und die Spalte
     * trägt den Eloquent-Cast `date`. Ein Wert, den Carbon nicht lesen kann,
     * endete damit als `InvalidFormatException` aus dem Setzen der Eigenschaft -
     * ungefangen, also 500 statt Formularhinweis. Ein Array (`start_date[]=x`)
     * brach schon vorher mit einem TypeError aus `preg_match()` ab. Und
     * `2026-13-45` kam durch: Carbon rechnet den Überlauf weiter und speicherte
     * stillschweigend den 14.02.2027 - ein Datum, das niemand eingegeben hat.
     *
     * Deshalb `createFromFormat('!Y-m-d')` mit Prüfung der Warnungen: Das `!`
     * setzt die Uhrzeit auf 00:00, und die Warnungen sind es, die den Überlauf
     * als Fehler kenntlich machen - ohne sie nimmt auch diese Fassung den
     * 13. Monat an. Das Formular liefert `type="date"`, also genau dieses
     * Format; alles andere stammt nicht aus der Oberfläche.
     */
    public static function normalize(mixed $value): string|false|null
    {
        $raw = trim(InputValidator::asString($value));
        if ($raw === '') {
            return null;
        }

        $parsed = \DateTimeImmutable::createFromFormat('!Y-m-d', $raw);
        $errors = \DateTimeImmutable::getLastErrors();
        $hasComplaint = is_array($errors)
            && (($errors['warning_count'] ?? 0) > 0 || ($errors['error_count'] ?? 0) > 0);

        if ($parsed === false || $hasComplaint) {
            return false;
        }

        return $parsed->format('Y-m-d');
    }

    /**
     * Die beiden Logo-Fristen aus dem Formular, oder false bei einem
     * ungültigen Datum. Ein fehlendes Feld lässt den bisherigen Wert stehen:
     * Die Fristen pflegt auch das Sponsoring-Team im eigenen Bereich, und ein
     * älteres Formular ohne die Felder soll sie nicht löschen.
     *
     * Bei einem neuen Projekt gibt es keinen bisherigen Wert; dort wird ein
     * fehlendes Feld zu `null`.
     *
     * @param array<string, mixed> $data
     * @return array{logo_deadline_print?: ?string, logo_deadline_online?: ?string}|false
     */
    public static function logoDeadlines(array $data, bool $isNewProject): array|false
    {
        $values = [];
        foreach (['logo_deadline_print', 'logo_deadline_online'] as $field) {
            if (!array_key_exists($field, $data)) {
                if ($isNewProject) {
                    $values[$field] = null;
                }
                continue;
            }

            $date = self::normalize($data[$field]);
            if ($date === false) {
                return false;
            }
            $values[$field] = $date;
        }

        return $values;
    }
}
