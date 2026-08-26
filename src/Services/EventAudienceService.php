<?php

declare(strict_types=1);

namespace App\Services;

use App\Exceptions\InvalidAudienceSourcesException;
use App\Models\Event;
use App\Models\EventAudienceSource;
use App\Models\Project;
use App\Models\Role;
use App\Models\User;
use App\Models\VoiceGroup;
use Illuminate\Database\Capsule\Manager as Capsule;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;

/**
 * Verwaltet Zielgruppen-Quellen von Terminen: Persistenz, Auflösung
 * berechtigter Nutzer und Sichtbarkeits-Query.
 */
class EventAudienceService
{
    private const ALLOWED_TYPES = [
        EventAudienceSource::TYPE_PROJECT_MEMBERS,
        EventAudienceSource::TYPE_ROLE,
        EventAudienceSource::TYPE_USER,
        EventAudienceSource::TYPE_VOICE_GROUP,
    ];

    /**
     * @return array<int, array{type:string, reference_id:int}>
     */
    public function getSources(Event $event): array
    {
        return $event->audienceSources()
            ->orderBy('id')
            ->get()
            ->map(static function (EventAudienceSource $source): array {
                return [
                    'type' => (string) $source->source_type,
                    'reference_id' => (int) $source->reference_id,
                ];
            })
            ->all();
    }

    /**
     * @param array<int, array{type:string, reference_id:int}> $sources
     * @throws InvalidAudienceSourcesException wenn keine der angegebenen Quellen mehr existiert
     */
    public function setSources(Event $event, array $sources): void
    {
        $normalized = $this->normalizeSources($sources);

        // Eine leere Eingabe heißt ausdrücklich "alle Mitglieder" und ist
        // erlaubt. Bleibt dagegen von einer angegebenen Zielgruppe nichts übrig,
        // würde sie sich stillschweigend auf alle verbreitern - das ist ein
        // Fehler, kein Standardfall.
        if ($sources !== [] && $normalized === []) {
            throw new InvalidAudienceSourcesException();
        }

        // Löschen und Neuanlegen gehören zusammen: Bricht das Schreiben mitten
        // im Austausch ab, stünde der Termin ohne Quellen da - und ein Termin
        // ohne Quellen gilt für alle Mitglieder.
        Capsule::connection()->transaction(function () use ($event, $normalized): void {
            $event->audienceSources()->delete();

            foreach ($normalized as $source) {
                $event->audienceSources()->create([
                    'source_type' => $source['type'],
                    'reference_id' => $source['reference_id'],
                ]);
            }
        });
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
     * eligibleUsersQuery() hängt allein an den Zielgruppen-Quellen des Termins.
     * Termine mit derselben Quellenmenge liefern deshalb zwangsläufig dieselben
     * Mitglieder - bei einer Serie sind das alle Termine. Statt je Termin eine
     * eigene Abfrage zu stellen, wird das Ergebnis über die Quellen-Signatur
     * wiederverwendet: Die Zahl der Abfragen hängt danach an der Zahl der
     * verschiedenen Zielgruppen, nicht mehr an der Zahl der Termine.
     *
     * Die Quellen sollten vorab geladen sein (`with('audienceSources')`), sonst
     * holt schon die Signatur je Termin eine eigene Abfrage.
     *
     * @param iterable<Event> $events
     * @return array<int, list<int>> Termin-Kennung => Kennungen der berechtigten Mitglieder
     */
    public function eligibleUserIdsForEvents(iterable $events): array
    {
        $idsByEvent = [];
        $idsBySignature = [];

        foreach ($events as $event) {
            $signature = $this->audienceSignature($event);

            if (!array_key_exists($signature, $idsBySignature)) {
                $idsBySignature[$signature] = $event->eligibleUsersQuery()
                    ->pluck('id')
                    ->map(static fn ($id): int => (int) $id)
                    ->all();
            }

            $idsByEvent[(int) $event->id] = $idsBySignature[$signature];
        }

        return $idsByEvent;
    }

    /**
     * Stabiler Fingerabdruck der Zielgruppen-Quellen eines Termins. Die Reihenfolge
     * der Quellen darf keine Rolle spielen, deshalb wird sortiert.
     */
    private function audienceSignature(Event $event): string
    {
        $sources = $event->relationLoaded('audienceSources')
            ? $event->audienceSources
            : $event->audienceSources()->get();

        $parts = [];
        foreach ($sources as $source) {
            $parts[] = (string) $source->source_type . ':' . (int) $source->reference_id;
        }

        sort($parts);

        return implode('|', $parts);
    }

    public function isUserEligible(Event $event, int $userId): bool
    {
        return $event->eligibleUsersQuery()
            ->where('users.id', $userId)
            ->exists();
    }

    /**
     * Events, für die der Nutzer betroffen ist: ohne Quellen (=alle) oder
     * mit passender Quelle (Projekt-/Rollen-/Stimmgruppen-Zugehörigkeit oder
     * direkte User-Quelle).
     */
    public function visibleEventsQuery(int $userId): Builder
    {
        $projectIds = $this->userReferenceIds($userId, 'projects', 'project_id');
        $roleIds = $this->userReferenceIds($userId, 'roles', 'role_id');
        $voiceGroupIds = $this->userReferenceIds($userId, 'voiceGroups', 'voice_group_id');

        return Event::query()->where(function ($query) use ($projectIds, $roleIds, $voiceGroupIds, $userId) {
            $query->whereDoesntHave('audienceSources')
                ->orWhereHas('audienceSources', function ($sourceQuery) use (
                    $projectIds,
                    $roleIds,
                    $voiceGroupIds,
                    $userId
                ) {
                    $sourceQuery->where(function ($match) use ($projectIds, $roleIds, $voiceGroupIds, $userId) {
                        $match->where(function ($q) use ($projectIds) {
                            $q->where('source_type', EventAudienceSource::TYPE_PROJECT_MEMBERS)
                                ->whereIn('reference_id', $projectIds === [] ? [0] : $projectIds);
                        })
                        ->orWhere(function ($q) use ($roleIds) {
                            $q->where('source_type', EventAudienceSource::TYPE_ROLE)
                                ->whereIn('reference_id', $roleIds === [] ? [0] : $roleIds);
                        })
                        ->orWhere(function ($q) use ($voiceGroupIds) {
                            $q->where('source_type', EventAudienceSource::TYPE_VOICE_GROUP)
                                ->whereIn('reference_id', $voiceGroupIds === [] ? [0] : $voiceGroupIds);
                        })
                        ->orWhere(function ($q) use ($userId) {
                            $q->where('source_type', EventAudienceSource::TYPE_USER)
                                ->where('reference_id', $userId);
                        });
                    });
                });
        });
    }

    /**
     * @param array<int, mixed> $raw
     * @return array<int, array{type:string, reference_id:int}>
     */
    public function normalizeSources(array $raw): array
    {
        $normalized = [];
        $seen = [];

        foreach ($raw as $item) {
            if (!is_array($item)) {
                continue;
            }

            $type = trim((string) ($item['type'] ?? ''));
            $referenceId = (int) ($item['reference_id'] ?? 0);

            if (!in_array($type, self::ALLOWED_TYPES, true) || $referenceId <= 0) {
                continue;
            }

            if (!$this->referenceExists($type, $referenceId)) {
                continue;
            }

            $key = $type . ':' . $referenceId;
            if (isset($seen[$key])) {
                continue;
            }

            $seen[$key] = true;
            $normalized[] = ['type' => $type, 'reference_id' => $referenceId];
        }

        return $normalized;
    }

    private function referenceExists(string $type, int $referenceId): bool
    {
        return match ($type) {
            EventAudienceSource::TYPE_PROJECT_MEMBERS => Project::query()->whereKey($referenceId)->exists(),
            EventAudienceSource::TYPE_ROLE => Role::query()->whereKey($referenceId)->exists(),
            EventAudienceSource::TYPE_VOICE_GROUP => VoiceGroup::query()->whereKey($referenceId)->exists(),
            EventAudienceSource::TYPE_USER => User::query()->whereKey($referenceId)->where('is_active', 1)->exists(),
            default => false,
        };
    }

    /**
     * @return array<int, int>
     */
    private function userReferenceIds(int $userId, string $relation, string $pivotColumn): array
    {
        $user = User::find($userId);
        if (!$user) {
            return [];
        }

        return $user->{$relation}()
            ->pluck($pivotColumn)
            ->map(static fn ($id): int => (int) $id)
            ->unique()
            ->values()
            ->all();
    }
}
