<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\Event;
use App\Models\User;
use App\Services\Audience\AudienceFilterService;
use App\Services\Audience\MemberProfile;

/**
 * Umfang einer Sitzung: welche Mitglieder die angemeldete Person bei Anwesenheit
 * und Anmeldung verwalten darf, und welche Termine sie dabei überhaupt sieht.
 */
class AttendanceScopeService
{
    private int $userId;
    private bool $canManageOwnVoiceGroup;
    private bool $canManageAttendanceAll;

    /** @var list<int> */
    private array $voiceGroupIds;

    /** @var array<int>|null */
    private ?array $manageableUserIdsCache = null;

    /** @var array<int, MemberProfile>|null */
    private ?array $profilesCache = null;

    private ?AudienceFilterService $filters = null;

    /**
     * Die Sitzung kommt von außen - dieselbe Regel wie bei den fünf Policies und
     * bei FileActor, und festgehalten in
     * tests/Unit/Services/AttendanceScopeServiceTakesTheSessionTest.
     *
     * Gelesen wurde sie zuvor an sechs Stellen direkt aus dem Superglobal. Zwei
     * Dinge hingen daran: Die Antwort konnte sich mitten im Vorgang ändern, weil
     * jeder Aufruf neu nachsah statt einmal beim Bauen - und geprüft werden
     * konnte der Dienst nur, indem man ein Superglobal setzte. Dass hier über
     * Rechte entschieden wird, macht beides schwerer als bei einem Dienst, der
     * nur etwas anzeigt.
     *
     * Auf Wahrheitswert geprüft, nicht strikt auf true: RoleMiddleware und die
     * Policies lesen dieselben Schlüssel ebenfalls nur truthy.
     *
     * @param array<string, mixed> $session
     */
    public function __construct(array $session)
    {
        $this->userId = (int) ($session['user_id'] ?? 0);
        $this->canManageOwnVoiceGroup = (bool) ($session['can_manage_own_voice_group'] ?? false);
        $this->canManageAttendanceAll = (bool) ($session['can_manage_attendance_all'] ?? false);
        $this->voiceGroupIds = array_values(array_map(
            static fn ($id): int => (int) $id,
            (array) ($session['voice_group_ids'] ?? [])
        ));
    }

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
        return $this->canManageOwnVoiceGroup || $this->canManageAttendanceAll;
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

        if (!$this->canManageAttendanceAll) {
            if ($this->voiceGroupIds === []) {
                return $this->manageableUserIdsCache = [];
            }

            $userVoiceGroupIds = $this->voiceGroupIds;

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
        if ($this->canManageAttendanceAll) {
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

        $userIds = $this->userId > 0 ? [$this->userId] : [];

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
