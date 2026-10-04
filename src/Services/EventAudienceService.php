<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\Event;
use App\Models\User;
use App\Services\Audience\AudienceFilterNormalizer;
use App\Services\Audience\AudienceFilterService;
use App\Services\Audience\AudienceFormInput;
use App\Services\Audience\InvalidAudienceFilterException;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;

/**
 * Zielgruppen von Terminen: Formular lesen, Filter speichern, berechtigte
 * Mitglieder auflösen und sichtbare Termine finden. Die Regel selbst steht in
 * AudienceFilterService.
 */
class EventAudienceService
{
    public const OWNER = 'event_id';

    public function __construct(
        private readonly AudienceFilterService $filters = new AudienceFilterService(),
        private readonly AudienceFilterNormalizer $normalizer = new AudienceFilterNormalizer()
    ) {
    }

    /**
     * Zielgruppe aus dem Formular. Ein Termin braucht mindestens eine Zeile;
     * "alle Mitglieder" ist eine Zeile mit Häkchen, kein leeres Formular - sonst
     * gäbe ein versehentlich geleertes Formular den Termin für alle frei.
     *
     * @param array<string, mixed> $data
     * @return list<array<string, list<int>>>
     * @throws InvalidAudienceFilterException
     */
    public function readRows(array $data): array
    {
        $sets = $this->normalizer->normalizeRows(AudienceFormInput::rows($data['audience'] ?? []));
        if ($sets === []) {
            throw new InvalidAudienceFilterException('Bitte mindestens eine Zielgruppe angeben.');
        }

        return $sets;
    }

    /**
     * @param list<array<string, list<int>>> $conditionSets
     */
    public function setAudience(Event $event, array $conditionSets): void
    {
        $this->filters->replaceForOwner(self::OWNER, (int) $event->id, $conditionSets);

        // Eine vorab geladene Beziehung (`with('audienceFilters.conditions')`)
        // trägt noch die eben gelöschten Filter. Jede Auswertung, die den
        // geladenen Stand bevorzugt, arbeitete danach mit der alten Zielgruppe.
        $event->unsetRelation('audienceFilters');
    }

    /**
     * @return list<array<string, list<int>>>
     */
    public function conditionSets(Event $event): array
    {
        return $event->audienceConditionSets();
    }

    /**
     * @return Collection<int, User>
     */
    public function resolveEligibleUsers(Event $event): Collection
    {
        return $event->eligibleUsersQuery()->get();
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
        return $event->eligibleUsersQuery()
            ->where('users.id', $userId)
            ->exists();
    }

    /**
     * Termine, deren Zielgruppe das Mitglied in mindestens einer Zeile trifft.
     */
    public function visibleEventsQuery(int $userId): Builder
    {
        $profile = $this->filters->profileOf($userId);
        $ids = $profile === null ? [] : $this->filters->matchingOwnerIds($profile, self::OWNER);

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
