<?php

declare(strict_types=1);

namespace App\Policies;

use App\Models\User;

/**
 * Decides whether the current session may edit a given member.
 *
 * Mirrors the rule previously inlined in UserController::index():
 * global can_edit_users, otherwise voice group representatives are
 * limited to members of their own voice groups.
 *
 * Ueber beiden Wegen steht die Rollenhierarchie: ein Mitglied, dessen hoechste
 * Rolle ueber dem eigenen Level liegt, bleibt unantastbar. UserController::update()
 * und ::deactivate() weisen solche Ziele ohnehin ab - ohne dieselbe Regel hier
 * haette die Mitgliederliste einen Bearbeiten-Link angeboten, der beim Klick
 * zwangslaeufig in einer 403-Meldung endet.
 */
class UserEditPolicy
{
    /**
     * @param array<string, mixed> $session
     */
    public function canEdit(array $session, User $target): bool
    {
        if ((int) ($target->is_active ?? 1) !== 1) {
            return false;
        }

        if ($this->outranksActor($session, $target)) {
            return false;
        }

        if (!empty($session['can_edit_users'])) {
            return true;
        }

        $ownGroupIds = $this->sessionVoiceGroupIds($session);
        if ($ownGroupIds === []) {
            return false;
        }

        $targetGroupIds = $target->voiceGroups
            ->pluck('id')
            ->map(static fn ($id): int => (int) $id)
            ->all();

        return array_intersect($ownGroupIds, $targetGroupIds) !== [];
    }

    /**
     * Editable member IDs for list views that only carry plain arrays.
     *
     * @param array<string, mixed> $session
     * @return array<int, true>
     */
    public function editableUserIdMap(array $session): array
    {
        if (!empty($session['can_edit_users'])) {
            $ids = User::query()->where('is_active', 1)->pluck('id');

            return array_fill_keys(
                $ids->map(static fn ($id): int => (int) $id)->all(),
                true
            );
        }

        $ownGroupIds = $this->sessionVoiceGroupIds($session);
        if ($ownGroupIds === []) {
            return [];
        }

        $ids = User::query()
            ->where('is_active', 1)
            ->whereHas('voiceGroups', static function ($query) use ($ownGroupIds): void {
                $query->whereIn('voice_groups.id', $ownGroupIds);
            })
            ->pluck('id');

        return array_fill_keys(
            $ids->map(static fn ($id): int => (int) $id)->all(),
            true
        );
    }

    /**
     * True when the target holds a role that outranks the acting session's own level.
     *
     * Die Rollen sind an beiden Aufrufstellen (UserQuery::getAllUsers() und
     * ::findById()) bereits eager-geladen, ein zusaetzlicher Query entsteht nicht.
     *
     * @param array<string, mixed> $session
     */
    private function outranksActor(array $session, User $target): bool
    {
        $actorLevel = (int) ($session['role_level'] ?? 0);
        $targetLevel = 0;

        foreach ($target->roles as $role) {
            $level = (int) ($role->hierarchy_level ?? 0);
            if ($level > $targetLevel) {
                $targetLevel = $level;
            }
        }

        return $targetLevel > $actorLevel;
    }

    /**
     * @param array<string, mixed> $session
     * @return array<int, int>
     */
    private function sessionVoiceGroupIds(array $session): array
    {
        $ids = (array) ($session['voice_group_ids'] ?? []);

        return array_values(array_map(static fn ($id): int => (int) $id, $ids));
    }
}
