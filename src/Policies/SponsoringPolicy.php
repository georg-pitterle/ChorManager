<?php

declare(strict_types=1);

namespace App\Policies;

use App\Models\Project;
use App\Models\Sponsor;
use App\Models\SponsoringContact;
use App\Models\Sponsorship;

/**
 * Policy für das Sponsoring. Zwei Rechte führen hierher:
 *
 *  - can_manage_sponsoring: das Vollrecht. Sieht, ändert und löscht alles,
 *    pflegt Pakete und Sponsor-Stammdaten und ist an kein Projekt gebunden.
 *  - can_create_own_sponsorships: darf alle Sponsoren und Vereinbarungen
 *    lesen, neue Sponsoren und Vereinbarungen anlegen und die selbst
 *    angelegten weiter pflegen. Der Rest bleibt gesperrt.
 *
 * Drei Entscheidungen, damit sie nicht bei jeder Durchsicht neu aufgeworfen
 * werden:
 *
 *  - Lesen ist absichtlich nicht eingeschränkt. Der Sinn des kleineren Rechts
 *    ist gerade, dass jedes Mitglied sieht, welche Firma bereits angefragt
 *    wurde - ohne diesen Überblick fragen zwei Personen dieselbe Firma an.
 *  - Beitragende dürfen Sponsoren anlegen, aber fremde Stammdaten nicht
 *    ändern. Ohne das Anlegen liesse sich keine neue Firma erfassen, und genau
 *    dafür lief die Excel-Liste weiter.
 *  - Vereinbarungen von Beitragenden hängen an einem laufenden Projekt oder an
 *    keinem. Abgeschlossene Projekte nachträglich zu ergänzen ist Sache des
 *    Sponsoring-Teams.
 */
class SponsoringPolicy
{
    private int $userId;
    private bool $canManageSponsoring;
    private bool $canCreateOwnSponsorships;

    public function __construct()
    {
        // Auf Wahrheitswert prüfen, nicht strikt auf true - Middleware und
        // Controller lesen dieselben Sitzungsschlüssel ebenfalls nur truthy.
        $this->userId = (int) ($_SESSION['user_id'] ?? 0);
        $this->canManageSponsoring = (bool) ($_SESSION['can_manage_sponsoring'] ?? false);
        $this->canCreateOwnSponsorships = (bool) ($_SESSION['can_create_own_sponsorships'] ?? false);
    }

    /**
     * Das Vollrecht: Pakete, fremde Einträge, Löschen von Sponsoren.
     */
    public function canManageAll(): bool
    {
        return $this->canManageSponsoring;
    }

    /**
     * Darf den Bereich sehen und eigene Einträge beitragen.
     */
    public function canContribute(): bool
    {
        return $this->canManageSponsoring || $this->canCreateOwnSponsorships;
    }

    public function canCreateSponsorship(): bool
    {
        return $this->canContribute();
    }

    public function canCreateSponsor(): bool
    {
        return $this->canContribute();
    }

    public function canEditSponsorship(Sponsorship $sponsorship): bool
    {
        return $this->canEditOwned($sponsorship->created_by_user_id);
    }

    public function canDeleteSponsorship(Sponsorship $sponsorship): bool
    {
        return $this->canEditSponsorship($sponsorship);
    }

    public function canEditSponsor(Sponsor $sponsor): bool
    {
        return $this->canEditOwned($sponsor->created_by_user_id);
    }

    /**
     * Kontakte gehören dem, der sie protokolliert hat; die Wiedervorlage darf
     * jeder Beitragende abhaken, weil sie oft jemand anderes erledigt.
     */
    public function canEditContact(SponsoringContact $contact): bool
    {
        return $this->canEditOwned($contact->user_id);
    }

    public function canCompleteFollowUp(): bool
    {
        return $this->canContribute();
    }

    /**
     * Kein Projekt ist erlaubt (allgemeine Anfrage ohne Projektbezug); sonst
     * muss das Projekt heute laufen, solange nicht das Vollrecht vorliegt.
     */
    public function canUseProject(?int $projectId): bool
    {
        if ($this->canManageSponsoring) {
            return true;
        }

        if (!$this->canCreateOwnSponsorships) {
            return false;
        }

        if ($projectId === null || $projectId <= 0) {
            return true;
        }

        $today = date('Y-m-d');

        return Project::where('id', $projectId)
            ->whereNotNull('start_date')
            ->whereNotNull('end_date')
            ->where('start_date', '<=', $today)
            ->where('end_date', '>=', $today)
            ->exists();
    }

    private function canEditOwned(mixed $ownerId): bool
    {
        if ($this->canManageSponsoring) {
            return true;
        }

        if (!$this->canCreateOwnSponsorships || $this->userId <= 0) {
            return false;
        }

        return $ownerId !== null && (int) $ownerId === $this->userId;
    }
}
