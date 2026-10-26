<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\Project;
use App\Models\SubVoice;
use App\Models\User;
use App\Models\VoiceGroup;
use App\Models\VoiceGroupList;
use App\Models\VoiceGroupListColumn;
use App\Util\PasswordHasher;

/**
 * Bausteine für die Tests der Stimmgruppen-Listen: Stimmgruppe, Mitglied,
 * Projekt, Liste und Spalte in genau dem Zustand, den ein Test braucht.
 */
trait VoiceGroupListFixtures
{
    private function makeVoiceGroup(string $name = 'Listengruppe'): VoiceGroup
    {
        return VoiceGroup::create(['name' => $name . ' ' . bin2hex(random_bytes(4))]);
    }

    private function makeSubVoice(VoiceGroup $group, string $name): SubVoice
    {
        return SubVoice::create(['name' => $name, 'voice_group_id' => $group->id]);
    }

    private function makeMember(
        ?VoiceGroup $group,
        ?SubVoice $subVoice = null,
        bool $active = true,
        string $lastName = 'Listenmitglied',
        string $firstName = 'Testperson'
    ): User {
        $user = User::create([
            'first_name' => $firstName,
            'last_name' => $lastName,
            'email' => 'voice-group-list-' . bin2hex(random_bytes(6)) . '@example.test',
            'password' => PasswordHasher::hash('x'),
            'is_active' => $active ? 1 : 0,
        ]);

        if ($group !== null) {
            $user->voiceGroups()->attach($group->id, ['sub_voice_id' => $subVoice?->id]);
        }

        return $user;
    }

    /** @param list<User> $members */
    private function makeProject(array $members = []): Project
    {
        $project = Project::create([
            'name' => 'Listenprojekt ' . bin2hex(random_bytes(4)),
            'start_date' => '2027-01-01',
            'end_date' => '2027-06-30',
        ]);
        $project->users()->attach(array_map(static fn(User $user): int => (int) $user->id, $members));

        return $project;
    }

    private function makeList(VoiceGroup $group, ?Project $project = null, string $title = 'Sommerkonzert'): VoiceGroupList
    {
        return VoiceGroupList::create([
            'voice_group_id' => $group->id,
            'title' => $title,
            'project_id' => $project?->id,
        ]);
    }

    private function makeColumn(
        VoiceGroupList $list,
        string $type = VoiceGroupListColumn::TYPE_CHECKBOX,
        string $label = 'Beitrag bezahlt',
        int $sortOrder = 0
    ): VoiceGroupListColumn {
        return VoiceGroupListColumn::create([
            'list_id' => $list->id,
            'label' => $label,
            'type' => $type,
            'sort_order' => $sortOrder,
        ]);
    }

    /** @return array<string, mixed> */
    private function representativeSession(User $user, VoiceGroup ...$groups): array
    {
        return [
            'user_id' => (int) $user->id,
            'can_keep_voice_group_lists' => true,
            'voice_group_ids' => array_map(static fn(VoiceGroup $group): int => (int) $group->id, $groups),
        ];
    }
}
