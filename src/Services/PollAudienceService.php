<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\Poll;
use App\Services\Audience\AudienceOwnerService;
use Carbon\Carbon;
use Illuminate\Database\Capsule\Manager as DB;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use App\Models\User;

/**
 * Zielgruppen von Umfragen: Formular lesen, Filter speichern, Mitglieder
 * auflösen und sichtbare Umfragen finden. Die Regel selbst steht in
 * AudienceFilterService, das gemeinsame Durchreichen in AudienceOwnerService;
 * ausgewertet wird live wie bei Terminen (EventAudienceService).
 */
class PollAudienceService extends AudienceOwnerService
{
    public const OWNER = 'poll_id';

    protected function ownerColumn(): string
    {
        return self::OWNER;
    }

    /**
     * @param list<array<string, list<int>>> $conditionSets
     */
    public function setAudience(Poll $poll, array $conditionSets): void
    {
        $this->replaceAudienceFor($poll, $conditionSets);
    }

    /**
     * @return list<array<string, list<int>>>
     */
    public function conditionSets(Poll $poll): array
    {
        return $this->conditionSetsFor($poll);
    }

    /**
     * Aktive Mitglieder der Zielgruppe, nur mit User::LIST_COLUMNS.
     *
     * @return Builder<User>
     */
    public function membersQuery(Poll $poll): Builder
    {
        return $this->membersQueryFor($poll);
    }

    public function isMember(Poll $poll, int $userId): bool
    {
        return $this->hasMember($this->membersQuery($poll), $userId);
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
        return $this->ownerIdsVisibleTo($userId);
    }

    /**
     * Offene Umfragen, bei denen das Mitglied noch abstimmen kann - die
     * kürzeste Frist zuerst.
     *
     * @return Collection<int, Poll>
     */
    public function awaitingVote(int $userId): Collection
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
