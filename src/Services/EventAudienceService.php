<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\Event;
use App\Services\Audience\AudienceOwnerService;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use App\Models\User;

/**
 * Zielgruppen von Terminen: Formular lesen, Filter speichern, berechtigte
 * Mitglieder auflösen und sichtbare Termine finden. Die Regel selbst steht in
 * AudienceFilterService, das gemeinsame Durchreichen in AudienceOwnerService.
 */
class EventAudienceService extends AudienceOwnerService
{
    public const OWNER = 'event_id';

    protected function ownerColumn(): string
    {
        return self::OWNER;
    }

    /**
     * @param list<array<string, list<int>>> $conditionSets
     */
    public function setAudience(Event $event, array $conditionSets): void
    {
        $this->replaceAudienceFor($event, $conditionSets);
    }

    /**
     * @return list<array<string, list<int>>>
     */
    public function conditionSets(Event $event): array
    {
        return $event->audienceConditionSets();
    }

    /**
     * Abfrage der Mitglieder, die für diesen Termin zählen: aktive Mitglieder,
     * auf die mindestens eine Zeile der Zielgruppe passt (innerhalb einer Zeile
     * ODER je Kategorie, UND über die Kategorien). Ein Termin ohne jede Zeile
     * trifft niemanden - "alle Mitglieder" ist eine Zeile ohne Bedingungen.
     *
     * Das ist die eine Stelle, an der die Frage "wer zählt für diesen Termin"
     * beantwortet wird; jeder Aufrufer baut darauf auf, statt die Bedingung
     * erneut zu formulieren.
     *
     * Stand vorher als `Event::eligibleUsersQuery()` am Modell und baute sich
     * den AudienceFilterService dort mit `new` selbst. Damit griff die
     * Datenschicht in die Dienstschicht, an der Dependency Injection vorbei, und
     * das Modell trug Wissen über die Auflösung von Zielgruppen, das nirgends
     * sonst in `src/Models` steht. Hier liegt der Dienst ohnehin schon bereit.
     *
     * Geladen werden nur User::LIST_COLUMNS - die Begrenzung steht in
     * AudienceOwnerService::membersQueryForSets(), damit sie für Termine,
     * Umfragen und Newsletter dieselbe ist.
     *
     * Die Bedingungsmengen kommen hier aus dem Model und nicht über
     * `conditionSetsFor()`: `$event->audienceConditionSets()` nutzt eine vorab
     * geladene Beziehung, und genau darauf baut `eligibleUserIdsForEvents()`
     * auf, um für eine Terminserie nicht je Termin nachzufragen.
     *
     * @return Builder<User>
     */
    public function eligibleUsersQuery(Event $event): Builder
    {
        return $this->membersQueryForSets($event->audienceConditionSets());
    }

    /**
     * @return Collection<int, User>
     */
    public function resolveEligibleUsers(Event $event): Collection
    {
        return $this->eligibleUsersQuery($event)->get();
    }

    /**
     * Berechtigte Mitglieder für mehrere Termine auf einmal.
     *
     * eligibleUsersQuery() hängt allein an den Bedingungsmengen des Termins.
     * Termine mit derselben Zielgruppe liefern deshalb zwangsläufig dieselben
     * Mitglieder - bei einer Serie sind das alle Termine. Statt je Termin eine
     * eigene Abfrage zu stellen, wird das Ergebnis über die Signatur der
     * Zielgruppe wiederverwendet: Die Zahl der Abfragen hängt danach an der Zahl
     * der verschiedenen Zielgruppen, nicht mehr an der Zahl der Termine.
     *
     * Die Filter sollten vorab geladen sein (`with('audienceFilters.conditions')`),
     * sonst holt schon die Signatur je Termin eine eigene Abfrage.
     *
     * @param iterable<Event> $events
     * @return array<int, list<int>> Termin-Kennung => Kennungen der berechtigten Mitglieder
     */
    public function eligibleUserIdsForEvents(iterable $events): array
    {
        $idsByEvent = [];
        $idsBySignature = [];

        foreach ($events as $event) {
            $sets = $event->audienceConditionSets();
            $signature = $this->signature($sets);

            if (!array_key_exists($signature, $idsBySignature)) {
                $idsBySignature[$signature] = $this->filters->membersQueryForSets($sets)
                    ->pluck('users.id')
                    ->map(static fn ($id): int => (int) $id)
                    ->all();
            }

            $idsByEvent[(int) $event->id] = $idsBySignature[$signature];
        }

        return $idsByEvent;
    }

    public function isUserEligible(Event $event, int $userId): bool
    {
        return $this->hasMember($this->eligibleUsersQuery($event), $userId);
    }

    /**
     * Termine, deren Zielgruppe das Mitglied in mindestens einer Zeile trifft.
     */
    public function visibleEventsQuery(int $userId): Builder
    {
        $ids = $this->ownerIdsVisibleTo($userId);

        return Event::query()->whereIn('events.id', $ids === [] ? [0] : $ids);
    }

    /**
     * Stabiler Fingerabdruck einer Zielgruppe; die Reihenfolge der Zeilen spielt
     * keine Rolle.
     *
     * @param list<array<string, list<int>>> $sets
     */
    private function signature(array $sets): string
    {
        $parts = array_map(fn (array $set): string => '[' . $this->normalizer->signature($set) . ']', $sets);
        sort($parts);

        return implode('|', $parts);
    }
}
