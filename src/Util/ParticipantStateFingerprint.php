<?php

declare(strict_types=1);

namespace App\Util;

use Illuminate\Database\Eloquent\Model;

/**
 * Fingerabdruck des gespeicherten Standes einer Teilnehmer-Tabelle, für genau
 * die übergebenen Mitglieder.
 *
 * Er trägt die optimistische Sperre an zwei Stellen: in der Anwesenheitsliste
 * (`attendances`) und bei den Vertretungseinträgen (`event_registrations`).
 * Beide Tabellen führen dieselben vier Spalten, deshalb genügt eine Stelle.
 * Zuvor stand die Reihung zweimal im Projekt - und war schon auseinandergelaufen:
 * eine Fassung schrieb die Trennzeichen als Escape-Folge, die andere als rohes,
 * im Diff unsichtbares Steuerzeichen.
 *
 * Gereiht wird je Zeile `user_id`, `status`, `note`, nach `user_id` sortiert.
 * Die beiden Trennzeichen sind Pflicht, nicht Zierde: `note` ist freier Text
 * eines Mitglieds. Ohne sie wäre die Reihung nicht eindeutig - eine Notiz, die
 * mit Kennung und Status der nächsten Zeile beginnt, ergäbe dieselbe
 * Zeichenkette wie der Stand, in dem diese Zeile wirklich existiert und die
 * Notiz leer ist. Zwei verschiedene Stände hätten denselben Fingerabdruck, und
 * die Sperre hielte den fremden Stand für den eigenen.
 *
 * `\x1f` (unit separator) und `\x1e` (record separator) stehen bewusst als
 * Escape-Folge und nicht als rohes Byte in der Zeichenkette: Rohe Steuerzeichen
 * sind in Editor, Diff und Review unsichtbar und überleben eine Normalisierung
 * der Datei nicht zuverlässig.
 */
final class ParticipantStateFingerprint
{
    /** Zwischen den Spalten einer Zeile. */
    private const FIELD_SEPARATOR = "\x1f";

    /** Zwischen den Zeilen. */
    private const ROW_SEPARATOR = "\x1e";

    /**
     * @param class-string<Model> $model Modell der Teilnehmer-Tabelle
     * @param list<int> $userIds
     */
    public static function of(string $model, int $eventId, array $userIds): string
    {
        if ($userIds === []) {
            return hash('sha256', '');
        }

        $parts = $model::query()
            ->where('event_id', $eventId)
            ->whereIn('user_id', $userIds)
            ->orderBy('user_id')
            ->get(['user_id', 'status', 'note'])
            ->map(static fn (Model $row): string => implode(self::FIELD_SEPARATOR, [
                (string) $row->user_id,
                (string) $row->status,
                (string) $row->note,
            ]))
            ->all();

        return hash('sha256', implode(self::ROW_SEPARATOR, $parts));
    }
}
