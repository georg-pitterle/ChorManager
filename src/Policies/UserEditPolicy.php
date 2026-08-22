<?php

declare(strict_types=1);

namespace App\Policies;

use App\Models\User;

/**
 * Decides whether the current session may edit a given member.
 *
 * Mirrors the rule previously inlined in UserController::index():
 * global can_edit_users, otherwise holders of can_manage_own_voice_group are
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

        // Die gemeinsame Stimmgruppe allein reicht nicht: UserController::update(),
        // ::deactivate() und ::invite() verlangen zusaetzlich can_manage_own_voice_group.
        // Da jede angemeldete Sitzung voice_group_ids traegt, haette die Policy sonst
        // jedem Mitgliederverwalter ohne dieses Recht einen Bearbeiten-Link auf die
        // eigene Stimmgruppe angeboten, den das Speichern mit "Keine Berechtigung"
        // quittiert.
        if (empty($session['can_manage_own_voice_group'])) {
            return false;
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
