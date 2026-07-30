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
        $canManageOwnVoiceGroup = (bool) ($_SESSION['can_manage_own_voice_group'] ?? false);
        $canManageAttendanceAll = (bool) ($_SESSION['can_manage_attendance_all'] ?? false);

        return $canManageOwnVoiceGroup || $canManageAttendanceAll;
    }

    /**
     * @return array<int>
     */
    public function getManageableUserIds(): array
    {
        $canManageAttendanceAll = (bool) ($_SESSION['can_manage_attendance_all'] ?? false);
        $userVoiceGroupIds = $_SESSION['voice_group_ids'] ?? [];
        $roleLevel = (int) ($_SESSION['role_level'] ?? 0);

        if (!$canManageAttendanceAll && $roleLevel < 80) {
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
