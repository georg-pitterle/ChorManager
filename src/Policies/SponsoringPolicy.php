<?php

declare(strict_types=1);

namespace App\Policies;

use App\Models\Project;
use App\Models\Sponsor;
use App\Models\SponsoringContact;
use App\Models\Sponsorship;
use App\Util\SponsorshipStatus;
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
 *    ändern. Ohne das Anlegen ließe sich keine neue Firma erfassen, und genau
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

    /**
     * Die Sitzung kommt von außen - siehe
     * tests/Unit/Policies/PoliciesReceiveTheSessionTest.
     *
     * @param array<string, mixed> $session
     */
    public function __construct(array $session)
    {
        // Auf Wahrheitswert prüfen, nicht strikt auf true - Middleware und
        // Controller lesen dieselben Sitzungsschlüssel ebenfalls nur truthy.
        $this->userId = (int) ($session['user_id'] ?? 0);
        $this->canManageSponsoring = (bool) ($session['can_manage_sponsoring'] ?? false);
        $this->canCreateOwnSponsorships = (bool) ($session['can_create_own_sponsorships'] ?? false);
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
     * Eine Wiedervorlage hakt ab, wem sie gehört - und wer bei der zugehörigen
     * Vereinbarung als zuständig eingetragen ist.
     *
     * Der Urheber allein reichte nicht: ist er im Urlaub oder ausgetreten,
     * blieb der Eintrag stehen, bis jemand mit dem Vollrecht ihn wegklickte.
     * Die Zuständigkeit ist die schmalste Erweiterung, die das löst - sie steht
     * an der Vereinbarung und benennt genau die Person, die den Faden ohnehin
     * weiterführt. Für alle Beitragenden zu öffnen bleibt ausgeschlossen: das
     * machte aus der Liste eine gemeinsame Aufgabenliste, in der jeder die
     * Einträge aller anderen wegklicken konnte.
     *
     * Ein Kontakt ohne Vereinbarung hat keine zuständige Person und bleibt
     * deshalb beim Urheber. Das Ändern der Zusammenfassung bleibt unberührt -
     * dafür gilt weiterhin canEditContact().
     */
    public function canCompleteFollowUp(SponsoringContact $contact): bool
    {
        if ($this->canEditOwned($contact->user_id)) {
            return true;
        }

        return $this->isAssignee($contact->sponsorship?->assigned_user_id);
    }

    /**
     * Darf eine Gegenleistung des Vereins abgehakt werden?
     *
     * Nur mit dem Vollrecht, auch beim Zurücknehmen: Die Gegenleistungen - etwa
     * die Übergabe der Logos an Grafik und Webdesign - erbringt das
     * Sponsoring-Team. Urheber und zuständige Person sehen die Liste, setzen den
     * Haken aber nicht selbst.
     */
    public function canTickBenefits(): bool
    {
        return $this->canManageSponsoring;
    }

    /**
     * Darf eine Leistung des Sponsors abgehakt werden?
     *
     * Wer den Vorgang ändern darf, ohnehin. Dazu die zuständige Person: Dass der
     * Sponsor sein Material bringt, verantwortet das Mitglied, das ihn betreut -
     * dieselbe schmale Erweiterung wie bei canCompleteFollowUp(). Alle
     * Beitragenden abhaken zu lassen bleibt ausgeschlossen, sonst wäre der Haken
     * keine Aussage mehr darüber, wer sich darum gekümmert hat.
     */
    public function canTickDeliverables(Sponsorship $sponsorship): bool
    {
        if ($this->canEditSponsorship($sponsorship)) {
            return true;
        }

        return $this->isAssignee($sponsorship->assigned_user_id);
    }

    /**
     * Darf der Betrag und die Anhänge dieser Vereinbarung gesehen werden?
     *
     * Der Urheber ohnehin. Dazu die zuständige Person: Sie erfüllt die Zusage -
     * Logo ins Programmheft, Freikarten zurücklegen - und muss dafür wissen,
     * was vereinbart wurde, und die Vertragsdatei öffnen können. Zuvor hing
     * beides allein am Urheber: Die zuständige Person sollte eine Zusage
     * einhalten, deren Inhalt ihr verdeckt blieb.
     *
     * Dass "sehen" damit weiter reicht als "ändern" (canEditSponsorship() bleibt
     * beim Urheber), ist die Absicht und kein Versehen: Den Betrag abtippen darf
     * weiterhin nur, wer die Vereinbarung geführt hat.
     *
     * Die Gesamtsummen folgen dem nicht - sie lassen sich keiner Urheberschaft
     * und keiner Zuständigkeit zuordnen, siehe canSeeFinancialTotals().
     */
    public function canSeeSponsorshipDetails(Sponsorship $sponsorship): bool
    {
        if ($this->canEditOwned($sponsorship->created_by_user_id)) {
            return true;
        }

        return $this->isAssignee($sponsorship->assigned_user_id);
    }

    public function canSeeSponsorDetails(Sponsor $sponsor): bool
    {
        return $this->canEditOwned($sponsor->created_by_user_id);
    }

    /**
     * Darf die Logo-Dateien dieses Sponsors hochladen, löschen und ansehen?
     *
     * Wer den Sponsor bearbeiten darf, ohnehin. Dazu die zuständige Person
     * einer zugesagten Vereinbarung: Sie besorgt das Logo für Plakat und Flyer,
     * und das Dashboard nennt sie unter "Logo fehlt". Ohne dieses Recht hätte
     * sie das besorgte Logo weder ablegen noch prüfen können.
     *
     * Bewusst nur das Logo: Mediadaten und Rahmenverträge am Sponsor bleiben
     * bei canSeeSponsorDetails(). Und nur bei einer Zusage - eine Anfrage oder
     * eine abgeschlossene Vereinbarung braucht kein Logo mehr.
     */
    public function canManageLogo(Sponsor $sponsor): bool
    {
        if ($this->canEditSponsor($sponsor)) {
            return true;
        }

        // Zuständigkeit erweitert nur den Zugang von Beitragenden, sie ersetzt
        // ihn nicht - wie bei isAssignee(). Sonst bliebe das Logo über die
        // zentrale Anhang-Route abrufbar, nachdem das Sponsoring-Recht entzogen
        // wurde.
        if (!$this->assignmentCounts()) {
            return false;
        }

        // Ohne die Kennung käme assignmentCounts() nicht bis hierher - es verlangt
        // sie selbst. Die zweite Prüfung auf null stand trotzdem hier und las wie
        // ein offener Fall, den es nicht gibt.
        return Sponsorship::query()
            ->where('sponsor_id', $sponsor->id)
            ->where('status', SponsorshipStatus::ACCEPTED)
            ->where('assigned_user_id', $this->userId)
            ->exists();
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
     * Beschränkung der Kontakthistorie auf dem Dashboard: Beitragende sehen dort
     * ihre eigene Arbeitsliste, nicht die aller anderen. Gibt null zurück, wenn
     * nicht eingeschränkt werden muss.
     *
     * Gilt nur für die Historie, nicht für die Wiedervorlagen - dort steht die
     * Zusammenfassung des Kontakts, und die bleibt beim Urheber
     * (canSeeContactDetails()). Die Wiedervorlagen gehen über
     * restrictFollowUpsToOwnWorkload().
     */
    public function ownContactUserIdFilter(): ?int
    {
        if ($this->canManageSponsoring) {
            return null;
        }

        return $this->userId;
    }

    /**
     * Beschränkung der Wiedervorlagen-Liste auf dem Dashboard: genau die
     * Einträge, die diese Person auch abhaken darf.
     *
     * Das ist absichtlich weiter als ownContactUserIdFilter(). Die Liste folgt
     * canCompleteFollowUp(), also Urheberschaft ODER Zuständigkeit an der
     * zugehörigen Vereinbarung. Nach Urheber allein gefiltert fiel genau der
     * Fall heraus, für den die Zuständigkeit überhaupt aufgenommen wurde: ist
     * der Urheber im Urlaub oder ausgetreten, stand sein Eintrag auf der
     * Übersicht der zuständigen Person nicht - sie hätte ihn nur auf der
     * Sponsor-Detailseite gefunden, wenn sie dort nachgesehen hätte.
     *
     * Die Zusammenfassung des fremden Kontakts kommt dabei nicht mit - aber
     * nicht, weil die Wiedervorlagen-Zeile sie nicht führte. Sie führt sie, und
     * zwar hinter canSeeContactDetails() (SponsoringDashboardController::
     * mapUpcomingFollowUp()). Genau dort liegt die Absicherung: Weil diese Liste
     * bewusst fremde Einträge aufnimmt, muss jede Zeile die Zusammenfassung
     * einzeln erfragen. Die Kontakthistorie darunter gibt sie ungeprüft weiter,
     * und das ist richtig - ihre Abfrage ist über ownContactUserIdFilter()
     * bereits auf eigene Kontakte eingeschränkt, hier also gerade nicht.
     *
     * @param Builder<SponsoringContact> $query
     * @return Builder<SponsoringContact>
     */
    public function restrictFollowUpsToOwnWorkload(Builder $query): Builder
    {
        if ($this->canManageSponsoring) {
            return $query;
        }

        $userId = $this->userId;
        // Dieselbe Bedingung wie in canCompleteFollowUp() - sie steht dafür in
        // assignmentCounts(). Trifft sie nicht zu, bleibt allein `user_id =
        // $userId`, also die eigenen Einträge; ohne Anmeldung ist das die 0 und
        // trifft auf keine Zeile zu.
        $includeAssigned = $this->assignmentCounts();

        return $query->where(function ($sub) use ($userId, $includeAssigned): void {
            $sub->where('user_id', $userId);

            if (!$includeAssigned) {
                return;
            }

            $sub->orWhereHas('sponsorship', static function ($agreement) use ($userId): void {
                $agreement->where('assigned_user_id', $userId);
            });
        });
    }

    /**
     * Kein Projekt ist erlaubt (allgemeine Anfrage ohne Projektbezug); sonst
     * muss das Projekt existieren und - solange nicht das Vollrecht vorliegt -
     * heute laufen.
     *
     * Die Existenz wird auch für das Vollrecht geprüft. Sonst nimmt
     * SponsorshipController::create() eine erfundene Kennung an, der
     * Fremdschlüssel auf projects weist sie ab, und der Versuch kommt als
     * nichtssagendes "Fehler beim Anlegen" zurück statt als Hinweis auf das
     * Projekt. Dieselbe Überlegung steht in ProjectMemberPolicy::canViewMembers().
     *
     * "Kein Projekt" ist allein die null. Eine Kennung, die kein Projekt sein
     * kann - 0 oder negativ -, galt hier zuvor ebenfalls als "kein Projekt" und
     * wurde durchgewinkt; der Controller reichte den Wert danach unverändert an
     * Sponsorship::create() weiter und landete in genau dem Fremdschlüsselfehler,
     * den die Existenzprüfung verhindern soll. Sie wird deshalb abgewiesen wie
     * ein unbekanntes Projekt.
     */
    public function canUseProject(?int $projectId): bool
    {
        if (!$this->canContribute()) {
            return false;
        }

        if ($projectId === null) {
            return true;
        }

        if ($projectId <= 0) {
            return false;
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
     * Das gilt hier auch für ein fehlendes Startdatum, und damit absichtlich
     * anders als in ProjectQuery::findCurrentProjectId(): Dort ist das
     * Startdatum Pflicht, weil eine Vorauswahl einen Zeitpunkt braucht, ab dem
     * das Projekt läuft. Diese Einschränkung hat eine andere Aufgabe - sie soll
     * abgeschlossene Projekte sperren, denn die nachträglich zu ergänzen ist
     * Sache des Sponsoring-Teams. Ein noch nicht terminiertes Projekt ist nicht
     * abgeschlossen, sondern das Gegenteil davon; es auszusperren hieße, dass
     * eine Zusage für das nächste Konzert erst nach der Terminplanung erfasst
     * werden könnte.
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

    /**
     * Ist die handelnde Person die an einer Vereinbarung eingetragene
     * Zuständige?
     *
     * Die Frage steht an zwei Entscheidungen - Wiedervorlage abhaken
     * (canCompleteFollowUp()) und Leistung des Sponsors abhaken (canTickDeliverables()) -
     * und war dort Zeile für Zeile dieselbe. Die Entscheidungen bleiben
     * getrennt: jede beantwortet weiterhin selbst, wem sie darüber hinaus
     * zustimmt. Nur ihre gemeinsame Teilfrage steht jetzt an einer Stelle.
     */
    private function isAssignee(mixed $assignedUserId): bool
    {
        if (!$this->assignmentCounts()) {
            return false;
        }

        return $assignedUserId !== null && (int) $assignedUserId === $this->userId;
    }

    /**
     * Zählt eine eingetragene Zuständigkeit für diese Sitzung überhaupt? Ohne
     * Beitragsrecht oder ohne Anmeldung nicht - die Zuständigkeit ist eine
     * Erweiterung für Beitragende, kein eigener Zugang.
     *
     * Eigene Methode, weil restrictFollowUpsToOwnWorkload() dieselbe Bedingung
     * braucht, dort aber nicht über isAssignee() gehen kann: Die Liste
     * entscheidet nicht über eine Zeile, sondern darüber, ob die Abfrage den
     * zweiten Zweig überhaupt bekommt.
     */
    private function assignmentCounts(): bool
    {
        return $this->canContribute() && $this->userId > 0;
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
