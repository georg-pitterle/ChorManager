<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\Event;
use App\Models\User;
use App\Services\Audience\AudienceFilterService;
use App\Services\Audience\MemberProfile;

/**
 * Session-based scope: which users may the current user manage
 * in attendance and registration contexts, and which events are
 * visible at all.
 */
class AttendanceScopeService
{
    /** @var array<int>|null */
    private ?array $manageableUserIdsCache = null;

    /** @var array<int, MemberProfile>|null */
    private ?array $profilesCache = null;

    private ?AudienceFilterService $filters = null;

    /**
     * Beide Rechte dürfen für andere eintragen, sie unterscheiden sich nur im Umfang:
     * die eigene Stimmgruppe oder alle Mitglieder. Das Hierarchie-Level spielt bewusst
     * keine Rolle mehr.
     *
     * Bis 20260902 stand hier ein drittes Recht, can_manage_attendance. Es hatte keinen
     * eigenen Umfang - getManageableUserIds() unten schränkt ohne _all auf dieselben
     * eigenen Stimmgruppen ein - und ist ersatzlos entfallen.
     */
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
        if ($this->manageableUserIdsCache !== null) {
            return $this->manageableUserIdsCache;
        }

        // Wer für niemanden eintragen darf, verwaltet auch niemanden. Ohne diese
        // Abfrage greift unten der Stimmgruppen-Zweig, sobald nur
        // can_manage_attendance_all fehlt - ein einfaches Mitglied bekam damit
        // seine ganze Stimmgruppe als verwaltbar gemeldet. Geschrieben wurde
        // dadurch nie etwas, weil die Schreibwege das Recht erneut prüfen; die
        // Antwort war trotzdem falsch, und darauf verlässt sich der nächste
        // Aufrufer womöglich allein.
        if (!$this->canManageOthers()) {
            return $this->manageableUserIdsCache = [];
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
     * Darf der aktuelle Nutzer Anwesenheit/Anmeldung dieses Termins überhaupt sehen?
     *
     * Sichtbar ist ein Termin, wenn man selbst zur Zielgruppe gehört oder wenn
     * mindestens ein verwaltbares Mitglied zur Zielgruppe gehört. Wer alle Mitglieder
     * verwalten darf, sieht jeden Termin.
     */
    public function canAccessEvent(Event $event): bool
    {
        if ((bool) ($_SESSION['can_manage_attendance_all'] ?? false)) {
            return true;
        }

        $sets = $event->audienceConditionSets();
        foreach ($this->accessibleProfiles() as $profile) {
            if ($this->filters()->fitsAny($profile, $sets)) {
                return true;
            }
        }

        return false;
    }

    /**
     * Eigenes Profil und - wer für andere eintragen darf - die Profile der
     * verwaltbaren Mitglieder, jedes für sich. Gemischt werden die Merkmale nicht:
     * Bei UND-Bedingungen ergäbe Sopran von einem und Projekt vom anderen einen
     * Zugriff, den keiner der beiden hat.
     *
     * @return array<int, MemberProfile>
     */
    private function accessibleProfiles(): array
    {
        if ($this->profilesCache !== null) {
            return $this->profilesCache;
        }

        $userId = (int) ($_SESSION['user_id'] ?? 0);
        $userIds = $userId > 0 ? [$userId] : [];

        if ($this->canManageOthers()) {
            $userIds = array_values(array_unique(array_merge($userIds, $this->getManageableUserIds())));
        }

        return $this->profilesCache = $userIds === [] ? [] : $this->filters()->profilesOf($userIds);
    }

    private function filters(): AudienceFilterService
    {
        return $this->filters ??= new AudienceFilterService();
    }
}
