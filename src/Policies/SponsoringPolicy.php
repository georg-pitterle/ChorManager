<?php

declare(strict_types=1);

namespace App\Policies;

use App\Models\Project;
use App\Models\Sponsor;
use App\Models\SponsoringContact;
use App\Models\Sponsorship;
use Illuminate\Database\Eloquent\Builder;

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
     * Kennung der handelnden Person, oder null ohne Anmeldung. Die Controller
     * schreiben sie als Urheber an neue Einträge; ohne diesen Zugang läse jeder
     * von ihnen denselben Sitzungsschlüssel noch einmal selbst aus.
     */
    public function currentUserId(): ?int
    {
        return $this->userId > 0 ? $this->userId : null;
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
     * Kontakte gehören dem, der sie protokolliert hat.
     */
    public function canEditContact(SponsoringContact $contact): bool
    {
        return $this->canEditOwned($contact->user_id);
    }

    /**
     * Eine Wiedervorlage hakt ab, wem sie gehört. Vorher durfte das jeder
     * Beitragende mit der Begründung, sie werde oft von jemand anderem
     * erledigt - das machte aus der Liste eine gemeinsame Aufgabenliste, in der
     * jeder die Einträge aller anderen wegklicken konnte.
     */
    public function canCompleteFollowUp(SponsoringContact $contact): bool
    {
        return $this->canEditOwned($contact->user_id);
    }

    /**
     * Darf der Betrag und die Anhänge dieser Vereinbarung gesehen werden?
     *
     * Bewusst eine eigene Frage, obwohl die Bedingung heute dieselbe ist wie
     * beim Ändern: "sehen" und "ändern" sind zwei Entscheidungen, und wer eine
     * davon später lockert, soll nicht ungewollt die andere mitlockern.
     */
    public function canSeeSponsorshipDetails(Sponsorship $sponsorship): bool
    {
        return $this->canEditOwned($sponsorship->created_by_user_id);
    }

    public function canSeeSponsorDetails(Sponsor $sponsor): bool
    {
        return $this->canEditOwned($sponsor->created_by_user_id);
    }

    /**
     * Darf die Zusammenfassung dieses Kontakts gelesen werden? Dass es ihn gibt
     * und wann er stattfand, bleibt sichtbar - das ist der Überblick, um den es
     * geht. Was besprochen wurde, ist Sache der Beteiligten.
     */
    public function canSeeContactDetails(SponsoringContact $contact): bool
    {
        return $this->canEditOwned($contact->user_id);
    }

    /**
     * Summen über alle Vereinbarungen - zugesagter Gesamtbetrag, Pipeline.
     * Anders als die Einzelbeträge lassen sie sich keiner Urheberschaft
     * zuordnen und bleiben deshalb dem Vollrecht vorbehalten.
     */
    public function canSeeFinancialTotals(): bool
    {
        return $this->canManageSponsoring;
    }

    /**
     * Beschränkung der Kontakt-Listen auf dem Dashboard: Beitragende sehen dort
     * ihre eigene Arbeitsliste, nicht die aller anderen. Gibt null zurück, wenn
     * nicht eingeschränkt werden muss.
     */
    public function ownContactUserIdFilter(): ?int
    {
        if ($this->canManageSponsoring) {
            return null;
        }

        return $this->userId;
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

        // Eine Existenzfrage, keine Liste: vorher lud die Prüfung jedes laufende
        // Projekt als Modell, um eine einzige Kennung darin zu suchen.
        return $this->restrictToRunning(Project::query())
            ->whereKey($projectId)
            ->exists();
    }

    /**
     * Projekte, die diese Person an eine Vereinbarung hängen darf. Das Formular
     * bietet genau diese Liste an - vorher standen dort alle Projekte, und die
     * Auswahl eines gesperrten Projekts verwarf beim Absenden das ganze
     * ausgefüllte Formular samt gewählter Anhänge.
     *
     * Welche Projekte das sind, entscheidet restrictToRunning() - dieselbe
     * Einschränkung, gegen die canUseProject() beim Absenden prüft.
     *
     * @return \Illuminate\Support\Collection<int, Project>
     */
    public function selectableProjects(): \Illuminate\Support\Collection
    {
        return $this->restrictToRunning(Project::query()->chronological())->get();
    }

    /**
     * Projekte, an denen die übergebenen Vereinbarungen bereits hängen, die aber
     * nicht mehr in `selectableProjects()` stehen - in aller Regel ein inzwischen
     * abgeschlossenes Projekt.
     *
     * Das Bearbeiten-Formular braucht sie als zusätzliche Auswahl. Ohne sie fehlt
     * dort genau der Eintrag, der gerade gilt: der Browser schickt "Kein Projekt",
     * und ein Speichern aus einem ganz anderen Grund - etwa ein korrigierter
     * Betrag - löst die Vereinbarung stillschweigend vom Projekt. Das
     * Anlegen-Formular bekommt sie bewusst nicht: dort wären sie eine Auswahl,
     * die canUseProject() beim Absenden abweist.
     *
     * @param iterable<Sponsorship> $sponsorships
     * @param \Illuminate\Support\Collection<int, Project> $selectable Ergebnis von selectableProjects()
     * @return array<int, Project> Vereinbarungs-Id => weiterhin zugeordnetes Projekt
     */
    public function retainedProjects(iterable $sponsorships, \Illuminate\Support\Collection $selectable): array
    {
        $selectableIds = $selectable
            ->map(static fn (Project $project): int => (int) $project->id)
            ->all();

        $retained = [];

        foreach ($sponsorships as $sponsorship) {
            $project = $sponsorship->project;

            if ($project === null || in_array((int) $project->id, $selectableIds, true)) {
                continue;
            }

            $retained[(int) $sponsorship->id] = $project;
        }

        return $retained;
    }

    /**
     * Beschränkt eine Projekt-Abfrage auf die heute laufenden, solange nicht das
     * Vollrecht vorliegt.
     *
     * Ein fehlendes Datum bedeutet dabei "offen", nicht "nicht laufend": ein
     * Projekt mit Beginn in der Vergangenheit und ohne gepflegtes Ende läuft.
     * Vorher verlangte die Prüfung beide Daten und sperrte solche Projekte aus.
     *
     * @param Builder<Project> $query
     * @return Builder<Project>
     */
    private function restrictToRunning(Builder $query): Builder
    {
        if ($this->canManageSponsoring) {
            return $query;
        }

        $today = date('Y-m-d');

        return $query
            ->where(function ($sub) use ($today): void {
                $sub->whereNull('start_date')->orWhere('start_date', '<=', $today);
            })
            ->where(function ($sub) use ($today): void {
                $sub->whereNull('end_date')->orWhere('end_date', '>=', $today);
            });
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
