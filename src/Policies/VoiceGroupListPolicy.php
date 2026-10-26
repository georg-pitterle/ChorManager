<?php

declare(strict_types=1);

namespace App\Policies;

use App\Models\VoiceGroupList;

/**
 * Wer Stimmgruppen-Listen sieht und führt.
 *
 * Beides muss zusammenkommen: das Recht can_keep_voice_group_lists und die
 * Mitgliedschaft in genau der Stimmgruppe der Liste. Kein anderes Recht führt
 * hierher, auch can_manage_own_voice_group nicht - das halten neben den
 * Stimmvertretungen auch Admin, Vorstand, Kassier und Chorleitung, und die
 * Listen sollen nur die Stimmvertretungen sehen.
 *
 * Lesen und Schreiben sind dasselbe Recht. Ein reines Leserecht gibt es nicht,
 * wie bei der Projektbesetzung (ProjectMemberPolicy).
 */
class VoiceGroupListPolicy
{
    private int $userId;
    private bool $canKeepLists;

    /** @var list<int> */
    private array $voiceGroupIds;

    /**
     * Die Sitzung kommt von außen - siehe
     * tests/Unit/Policies/PoliciesReceiveTheSessionTest.
     *
     * @param array<string, mixed> $session
     */
    public function __construct(array $session)
    {
        // Truthy wie RoleMiddleware und die übrigen Policies, nicht strikt true.
        $this->userId = (int) ($session['user_id'] ?? 0);
        $this->canKeepLists = (bool) ($session['can_keep_voice_group_lists'] ?? false);
        $this->voiceGroupIds = array_values(array_map(
            static fn ($id): int => (int) $id,
            (array) ($session['voice_group_ids'] ?? [])
        ));
    }

    /**
     * Die Stimmgruppen, deren Listen die Person führt; ohne das Recht keine.
     *
     * @return list<int>
     */
    public function accessibleVoiceGroupIds(): array
    {
        return $this->canKeepLists ? $this->voiceGroupIds : [];
    }

    public function canUseVoiceGroup(int $voiceGroupId): bool
    {
        return in_array($voiceGroupId, $this->accessibleVoiceGroupIds(), true);
    }

    public function canAccessList(VoiceGroupList $list): bool
    {
        return $this->canUseVoiceGroup((int) $list->voice_group_id);
    }

    public function currentUserId(): int
    {
        return $this->userId;
    }
}
