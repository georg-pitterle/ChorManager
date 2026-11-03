<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\Poll;
use App\Models\PollOption;
use App\Models\User;
use App\Models\VoiceGroup;
use App\Services\Audience\AudienceFilterService;
use App\Util\PasswordHasher;
use Carbon\Carbon;

/**
 * Bausteine für die Tests der Umfragen: Mitglied, Umfrage mit Antworten und
 * Zielgruppe, Sitzung.
 */
trait PollFixtures
{
    private function makePollMember(string $lastName = 'Umfragemitglied', bool $active = true): User
    {
        return User::create([
            'first_name' => 'Testperson',
            'last_name' => $lastName,
            'email' => 'poll-' . bin2hex(random_bytes(6)) . '@example.test',
            'password' => PasswordHasher::hash('x'),
            'is_active' => $active ? 1 : 0,
        ]);
    }

    private function makePollVoiceGroup(User ...$members): VoiceGroup
    {
        $group = VoiceGroup::create(['name' => 'Umfragegruppe ' . bin2hex(random_bytes(4))]);
        foreach ($members as $member) {
            $member->voiceGroups()->attach($group->id);
        }

        return $group;
    }

    /**
     * Umfrage mit Antworten in dieser Reihenfolge. Ohne $audience geht sie an
     * alle Mitglieder; sonst ist $audience eine Bedingungsmenge
     * (z. B. ['user' => [3, 4]]).
     *
     * @param list<string> $labels
     * @param array<string, list<int>>|null $audience
     */
    private function makePoll(
        array $labels = ['Wachau', 'Salzkammergut', 'Bleiben wir zu Hause'],
        bool $anonymous = false,
        bool $multiple = false,
        ?Carbon $closesAt = null,
        ?array $audience = [],
        string $title = 'Ausflug im Mai: wohin?',
        string $kind = Poll::KIND_CHOICE
    ): Poll {
        $poll = Poll::create([
            'title' => $title,
            'answer_kind' => $kind,
            'allows_multiple' => $multiple,
            'is_anonymous' => $anonymous,
            'closes_at' => $closesAt ?? Carbon::now()->addDays(7),
        ]);

        // Eine offene Frage hat keine Antworten zur Auswahl.
        foreach ($kind === Poll::KIND_TEXT ? [] : $labels as $index => $label) {
            PollOption::create(['poll_id' => $poll->id, 'label' => $label, 'sort_order' => $index]);
        }

        if ($audience !== null) {
            (new AudienceFilterService())->create($audience, 'poll_id', (int) $poll->id);
        }

        return $poll;
    }

    /** @return array<string, mixed> */
    private function pollSession(User $user, bool $canManage = false): array
    {
        return [
            'user_id' => (int) $user->id,
            'can_manage_polls' => $canManage,
        ];
    }

    private function optionId(Poll $poll, int $index): int
    {
        return (int) $poll->options()->get()[$index]->id;
    }
}
