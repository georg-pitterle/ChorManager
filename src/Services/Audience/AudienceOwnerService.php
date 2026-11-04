<?php

declare(strict_types=1);

namespace App\Services\Audience;

use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

/**
 * Gemeinsamer Unterbau der Dienste, die eine Zielgruppe besitzen - Termine,
 * Umfragen und Newsletter.
 *
 * Jeder dieser Dienste tat dasselbe: Formular lesen, Filter für seinen Besitzer
 * ersetzen, Mitglieder auflösen, Sichtbarkeit prüfen. Unterschieden hat sie
 * allein die Spalte in `audience_filters` - und trotzdem stand `readRows()`
 * wortgleich dreimal da. Das ist der Rechte-Pfad: Wer die Regel ändert, musste
 * an drei Stellen daran denken, und eine vergessene fiel nirgends auf, weil
 * jede Fassung für sich richtig aussah.
 *
 * Was hier **nicht** steht: die Regel selbst. Wie Bedingungen ausgewertet
 * werden, entscheidet weiterhin allein AudienceFilterService. Diese Klasse
 * reicht nur durch und trägt die Besitzer-Spalte.
 */
abstract class AudienceOwnerService
{
    public function __construct(
        protected readonly AudienceFilterService $filters = new AudienceFilterService(),
        protected readonly AudienceFilterNormalizer $normalizer = new AudienceFilterNormalizer()
    ) {
    }

    /**
     * Die Spalte in `audience_filters`, die diesen Besitzer trägt - etwa
     * `event_id`. AudienceFilterService weist eine unbekannte Spalte ab, der
     * Wert kann also nicht still daneben liegen.
     */
    abstract protected function ownerColumn(): string;

    /**
     * Zielgruppen-Zeilen aus dem Formular, ohne Pflicht auf mindestens eine.
     *
     * Für den Newsletter ist das der Normalfall: Er darf ohne Zielgruppe
     * gespeichert werden, erst der Versand verlangt Empfänger.
     *
     * @param array<string, mixed> $data
     * @return list<array<string, list<int>>>
     * @throws InvalidAudienceFilterException
     */
    protected function rowsFrom(array $data): array
    {
        return $this->normalizer->normalizeRows(AudienceFormInput::rows($data['audience'] ?? []));
    }

    /**
     * Zielgruppen-Zeilen aus dem Formular; mindestens eine ist Pflicht.
     *
     * "Alle Mitglieder" ist eine Zeile mit Häkchen, kein leeres Formular - sonst
     * gäbe ein versehentlich geleertes Formular den Termin oder die Umfrage für
     * alle frei.
     *
     * @param array<string, mixed> $data
     * @return list<array<string, list<int>>>
     * @throws InvalidAudienceFilterException
     */
    public function readRows(array $data): array
    {
        $sets = $this->rowsFrom($data);
        if ($sets === []) {
            throw new InvalidAudienceFilterException('Bitte mindestens eine Zielgruppe angeben.');
        }

        return $sets;
    }

    /**
     * Ersetzt die Filter dieses Besitzers und verwirft die geladene Beziehung.
     *
     * Das Verwerfen gehört dazu und nicht an die Aufrufstelle: Eine vorab
     * geladene Beziehung (`with('audienceFilters.conditions')`) trägt noch die
     * eben gelöschten Filter, und jede Auswertung, die den geladenen Stand
     * bevorzugt, arbeitete danach mit der alten Zielgruppe.
     *
     * @param list<array<string, list<int>>> $conditionSets
     */
    protected function replaceAudienceFor(Model $owner, array $conditionSets): void
    {
        $this->filters->replaceForOwner($this->ownerColumn(), (int) $owner->getKey(), $conditionSets);
        $owner->unsetRelation('audienceFilters');
    }

    /**
     * @return list<array<string, list<int>>>
     */
    protected function conditionSetsFor(Model $owner): array
    {
        $ownerId = (int) $owner->getKey();

        return $this->filters->conditionSetsForOwners($this->ownerColumn(), [$ownerId])[$ownerId];
    }

    /**
     * Aktive Mitglieder der Zielgruppe dieses Besitzers, nur mit
     * User::LIST_COLUMNS.
     *
     * Die Aufrufer reichen die Modelle unverändert an Templates und
     * Mail-Erzeugung weiter; der Passwort-Hash hat dort nichts verloren. Wer
     * eine weitere Spalte braucht, nimmt sie in LIST_COLUMNS auf - nicht in
     * einen eigenen select() an der Aufrufstelle, sonst fällt die Grenze wieder
     * auseinander.
     *
     * @return Builder<User>
     */
    protected function membersQueryFor(Model $owner): Builder
    {
        return $this->membersQueryForSets($this->conditionSetsFor($owner));
    }

    /**
     * @param list<array<string, list<int>>> $conditionSets
     * @return Builder<User>
     */
    protected function membersQueryForSets(array $conditionSets): Builder
    {
        return $this->filters
            ->membersQueryForSets($conditionSets)
            ->select(array_map(static fn (string $column): string => 'users.' . $column, User::LIST_COLUMNS));
    }

    protected function hasMember(Builder $membersQuery, int $userId): bool
    {
        if ($userId <= 0) {
            return false;
        }

        return $membersQuery->where('users.id', $userId)->exists();
    }

    /**
     * Besitzer, deren Zielgruppe das Mitglied in mindestens einer Zeile trifft.
     *
     * @return list<int>
     */
    protected function ownerIdsVisibleTo(int $userId): array
    {
        $profile = $this->filters->profileOf($userId);

        return $profile === null ? [] : $this->filters->matchingOwnerIds($profile, $this->ownerColumn());
    }
}
