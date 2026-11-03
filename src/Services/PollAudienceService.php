<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\Poll;
use Carbon\Carbon;
use Illuminate\Database\Capsule\Manager as DB;
use App\Models\User;
use App\Services\Audience\AudienceFilterNormalizer;
use App\Services\Audience\AudienceFilterService;
use App\Services\Audience\AudienceFormInput;
use App\Services\Audience\InvalidAudienceFilterException;
use Illuminate\Database\Eloquent\Builder;

/**
 * Zielgruppen von Umfragen: Formular lesen, Filter speichern, Mitglieder
 * auflösen und sichtbare Umfragen finden. Die Regel selbst steht in
 * AudienceFilterService; ausgewertet wird live wie bei Terminen
 * (EventAudienceService).
 */
class PollAudienceService
{
    public const OWNER = 'poll_id';

    public function __construct(
        private readonly AudienceFilterService $filters = new AudienceFilterService(),
        private readonly AudienceFilterNormalizer $normalizer = new AudienceFilterNormalizer()
    ) {
    }

    /**
     * Zielgruppe aus dem Formular. Eine Umfrage braucht mindestens eine Zeile;
     * "alle Mitglieder" ist eine Zeile mit Häkchen, kein leeres Formular.
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
    public function setAudience(Poll $poll, array $conditionSets): void
    {
        $this->filters->replaceForOwner(self::OWNER, (int) $poll->id, $conditionSets);
        $poll->unsetRelation('audienceFilters');
    }

    /**
     * @return list<array<string, list<int>>>
     */
    public function conditionSets(Poll $poll): array
    {
        return $this->filters->conditionSetsForOwners(self::OWNER, [(int) $poll->id])[(int) $poll->id];
    }

    /**
     * Aktive Mitglieder der Zielgruppe, nur mit User::LIST_COLUMNS.
     *
     * @return Builder<User>
     */
    public function membersQuery(Poll $poll): Builder
    {
        return $this->filters
            ->membersQueryForOwner(self::OWNER, (int) $poll->id)
            ->select(array_map(static fn (string $column): string => 'users.' . $column, User::LIST_COLUMNS));
    }

    public function isMember(Poll $poll, int $userId): bool
    {
        if ($userId <= 0) {
            return false;
        }

        return $this->membersQuery($poll)->where('users.id', $userId)->exists();
    }

    public function memberCount(Poll $poll): int
    {
        return $this->filters->membersQueryForOwner(self::OWNER, (int) $poll->id)->count();
    }

    /**
     * Umfragen, deren Zielgruppe das Mitglied in mindestens einer Zeile trifft.
     *
     * @return list<int>
     */
    public function visiblePollIds(int $userId): array
    {
        $profile = $this->filters->profileOf($userId);

        return $profile === null ? [] : $this->filters->matchingOwnerIds($profile, self::OWNER);
    }

    /**
     * Offene Umfragen, bei denen das Mitglied noch abstimmen kann - die
     * kürzeste Frist zuerst.
     *
     * @return \Illuminate\Database\Eloquent\Collection<int, Poll>
     */
    public function awaitingVote(int $userId): \Illuminate\Database\Eloquent\Collection
    {
        // Erst die offenen Umfragen ohne eigene Stimme, dann nur deren
        // Zielgruppen prüfen: Die Startseite lädt bei jedem Aufruf, und beendete
        // Umfragen sammeln sich an.
        $candidates = Poll::query()
            ->where('closes_at', '>', Carbon::now())
            ->whereNotExists(static function ($sub) use ($userId): void {
                $sub->selectRaw('1')
                    ->from('poll_participants')
                    ->whereColumn('poll_participants.poll_id', 'polls.id')
                    ->where('poll_participants.user_id', $userId);
            })
            ->orderBy('closes_at')
            ->get();
        $profile = $candidates->isEmpty() ? null : $this->filters->profileOf($userId);
        if ($profile === null) {
            return $candidates->take(0);
        }

        $sets = $this->filters->conditionSetsForOwners(self::OWNER, $candidates->pluck('id')->map('intval')->all());

        return $candidates
            ->filter(fn (Poll $poll): bool => $this->filters->fitsAny($profile, $sets[(int) $poll->id] ?? []))
            ->values();
    }

    /**
     * Ob jemand abgestimmt hat, der nicht mehr zur Zielgruppe gehört - etwa
     * nachdem sie verkleinert wurde. Dann taugt "x von y" nicht mehr.
     */
    public function hasParticipantsOutside(Poll $poll): bool
    {
        $memberIds = $this->filters
            ->membersQueryForOwner(self::OWNER, (int) $poll->id)
            ->select('users.id')
            ->toBase();

        return DB::table('poll_participants')
            ->where('poll_id', $poll->id)
            ->whereNotIn('user_id', $memberIds)
            ->exists();
    }
}
