<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\User;

/**
 * Session-based scope: which users may the current user manage
 * in attendance and registration contexts.
 */
class AttendanceScopeService
{
    public function canManageOthers(): bool
    {
        $canManageUsers = (bool) ($_SESSION['can_manage_users'] ?? false);
        $canManageOwnVoiceGroup = (bool) ($_SESSION['can_manage_own_voice_group'] ?? false);

        return $canManageUsers || $canManageOwnVoiceGroup;
    }

    /**
     * @return array<int>
     */
    public function getManageableUserIds(): array
    {
        $canManageUsers = (bool) ($_SESSION['can_manage_users'] ?? false);
        $userVoiceGroupIds = $_SESSION['voice_group_ids'] ?? [];
        $roleLevel = (int) ($_SESSION['role_level'] ?? 0);

        if (!$canManageUsers && $roleLevel < 80) {
            if (empty($userVoiceGroupIds)) {
                return [];
            }

            return User::whereHas('voiceGroups', function ($query) use ($userVoiceGroupIds) {
                $query->whereIn('voice_group_id', $userVoiceGroupIds);
            })
                ->where('is_active', 1)
                ->pluck('id')
                ->map(static fn($id) => (int) $id)
                ->all();
        }

        return User::where('is_active', 1)
            ->pluck('id')
            ->map(static fn($id) => (int) $id)
            ->all();
    }
}
