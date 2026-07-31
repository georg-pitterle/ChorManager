<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\Event;
use App\Models\EventAudienceSource;
use App\Models\User;
use Illuminate\Database\Capsule\Manager as Capsule;

/**
 * Session-based scope: which users may the current user manage
 * in attendance and registration contexts, and which events are
 * visible at all.
 */
class AttendanceScopeService
{
    /** @var array<int>|null */
    private ?array $manageableUserIdsCache = null;

    /** @var array<string, array<int>>|null */
    private ?array $audienceSetsCache = null;

    /**
     * Alle drei Rechte duerfen fuer andere eintragen, sie unterscheiden sich nur im Umfang.
     * Das Hierarchie-Level spielt bewusst keine Rolle mehr.
     */
    public function canManageOthers(): bool
    {
        $canManageOwnVoiceGroup = (bool) ($_SESSION['can_manage_own_voice_group'] ?? false);
        $canManageAttendance = (bool) ($_SESSION['can_manage_attendance'] ?? false);
        $canManageAttendanceAll = (bool) ($_SESSION['can_manage_attendance_all'] ?? false);

        return $canManageOwnVoiceGroup || $canManageAttendance || $canManageAttendanceAll;
    }

    /**
     * @return array<int>
     */
    public function getManageableUserIds(): array
    {
        if ($this->manageableUserIdsCache !== null) {
            return $this->manageableUserIdsCache;
        }

        $canManageAttendanceAll = (bool) ($_SESSION['can_manage_attendance_all'] ?? false);
        $userVoiceGroupIds = $_SESSION['voice_group_ids'] ?? [];

        if (!$canManageAttendanceAll) {
            if (empty($userVoiceGroupIds)) {
                return $this->manageableUserIdsCache = [];
            }

            return $this->manageableUserIdsCache = User::whereHas(
                'voiceGroups',
                function ($query) use ($userVoiceGroupIds) {
                    $query->whereIn('voice_group_id', $userVoiceGroupIds);
                }
            )
                ->where('is_active', 1)
                ->pluck('id')
                ->map(static fn($id) => (int) $id)
                ->all();
        }

        return $this->manageableUserIdsCache = User::where('is_active', 1)
            ->pluck('id')
            ->map(static fn($id) => (int) $id)
            ->all();
    }

    /**
     * Darf der aktuelle Nutzer Anwesenheit/Anmeldung dieses Termins ueberhaupt sehen?
     *
     * Sichtbar ist ein Termin, wenn man selbst zur Zielgruppe gehoert oder wenn
     * mindestens ein verwaltbares Mitglied zur Zielgruppe gehoert. Wer alle Mitglieder
     * verwalten darf, sieht jeden Termin.
     */
    public function canAccessEvent(Event $event): bool
    {
        if ((bool) ($_SESSION['can_manage_attendance_all'] ?? false)) {
            return true;
        }

        $sources = $event->relationLoaded('audienceSources')
            ? $event->audienceSources
            : $event->audienceSources()->get();

        // Ohne Zielgruppen-Quelle gilt der Termin fuer alle aktiven Mitglieder.
        if ($sources->isEmpty()) {
            return true;
        }

        $sets = $this->accessibleAudienceSets();

        foreach ($sources as $source) {
            $referenceId = (int) $source->reference_id;
            $matches = match ((string) $source->source_type) {
                EventAudienceSource::TYPE_USER => in_array($referenceId, $sets['users'], true),
                EventAudienceSource::TYPE_VOICE_GROUP => in_array($referenceId, $sets['voice_groups'], true),
                EventAudienceSource::TYPE_PROJECT_MEMBERS => in_array($referenceId, $sets['projects'], true),
                EventAudienceSource::TYPE_ROLE => in_array($referenceId, $sets['roles'], true),
                default => false,
            };

            if ($matches) {
                return true;
            }
        }

        return false;
    }

    /**
     * Zielgruppen-Merkmale, ueber die der aktuelle Nutzer Zugriff auf einen Termin bekommt:
     * seine eigenen und - sofern er fuer andere eintragen darf - die der verwaltbaren Mitglieder.
     *
     * @return array<string, array<int>>
     */
    private function accessibleAudienceSets(): array
    {
        if ($this->audienceSetsCache !== null) {
            return $this->audienceSetsCache;
        }

        $userId = (int) ($_SESSION['user_id'] ?? 0);
        $userIds = $userId > 0 ? [$userId] : [];

        if ($this->canManageOthers()) {
            $userIds = array_values(array_unique(array_merge($userIds, $this->getManageableUserIds())));
        }

        if ($userIds === []) {
            return $this->audienceSetsCache = [
                'users' => [],
                'voice_groups' => [],
                'projects' => [],
                'roles' => [],
            ];
        }

        return $this->audienceSetsCache = [
            'users' => $userIds,
            'voice_groups' => $this->pivotReferenceIds('user_voice_groups', 'voice_group_id', $userIds),
            'projects' => $this->pivotReferenceIds('project_users', 'project_id', $userIds),
            'roles' => $this->pivotReferenceIds('user_roles', 'role_id', $userIds),
        ];
    }

    /**
     * @param array<int> $userIds
     * @return array<int>
     */
    private function pivotReferenceIds(string $table, string $column, array $userIds): array
    {
        return Capsule::table($table)
            ->whereIn('user_id', $userIds)
            ->distinct()
            ->pluck($column)
            ->map(static fn($id): int => (int) $id)
            ->all();
    }
}
