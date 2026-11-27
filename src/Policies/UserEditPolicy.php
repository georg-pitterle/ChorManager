<?php

declare(strict_types=1);

namespace App\Policies;

use App\Models\User;

/**
 * Decides whether the current session may edit a given member.
 *
 * Mehrere Stufen, weil das Bearbeiten-Formular mehrere Zwecke bedient:
 *  - canEdit(): darf das Formular überhaupt geöffnet werden? Neben
 *    can_edit_users und can_manage_own_voice_group (eigene Stimmgruppe) reicht
 *    dafür auch can_manage_project_members, dessen Träger die Projektzuordnung
 *    über genau dieses Formular pflegt.
 *  - canEditProfile(): dürfen Name, Rollen und Stimmgruppen geschrieben
 *    werden? Das bleibt can_edit_users und der eigenen Stimmgruppe vorbehalten.
 *    Ein reiner Projektmitglieder-Verwalter ändert nur die Projektzuordnung.
 *  - canEditProjects(): darf die Projektzuordnung geschrieben werden? Genau das
 *    ist die Schreibstufe des Projektmitglieder-Verwalters.
 *  - canArchive(): darf das Mitglied stillgelegt oder zurückgeholt werden? Als
 *    einzige Stufe ohne die Frage, ob das Ziel aktiv ist - sonst bliebe ein
 *    archiviertes Mitglied für immer archiviert.
 *  - canEditEmail(): die Adresse selbst hängt allein an can_edit_users. Eine
 *    fremde Adresse umbiegen und anschließend eine Einladung darauf auslösen
 *    ergibt einen vollständigen Übernahmepfad auf das Zielkonto; für den
 *    Projektmitglieder-Verwalter ist genau der ausgeschlossen, und für den
 *    Stimmgruppen-Verwalter darf nichts anderes gelten.
 *
 * Über den Wegen, die das Konto selbst betreffen, steht die Rollenhierarchie: ein
 * Mitglied, dessen höchste Rolle über dem eigenen Level liegt, behält Name,
 * Rollen, Stimmgruppen, Adresse und Archiv-Zustand für sich.
 * UserController::deactivate() weist solche Ziele ohnehin ab - ohne dieselbe
 * Regel hier hätte die Mitgliederliste einen Knopf angeboten, der beim Klick
 * zwangsläufig in einer 403-Meldung endet.
 *
 * Die Projektzuordnung ist davon ausgenommen (Antwort A aus Review-Lauf 87). Sie
 * ist eine Besetzungsliste und keine Kontoverwaltung: sie vergibt kein Recht und
 * eröffnet keinen Übernahmepfad auf das Zielkonto. Die Besetzungsseite eines
 * Projekts hat die Hierarchie deshalb nie geprüft - mit ihr könnte die
 * Chorleitung den Vorstand in kein Projekt aufnehmen, siehe ProjectMemberPolicy.
 * Hier galt sie trotzdem, und dieselbe Zuordnung war damit über /projects erlaubt
 * und über /users gesperrt. Zwei Antworten auf eine Frage; jetzt gilt überall die
 * der Besetzungsseite.
 */
class UserEditPolicy
{
    private bool $canEditUsers;
    private bool $canManageOwnVoiceGroup;
    private bool $canManageProjectMembers;
    private int $actorLevel;
    /** @var array<int, int> */
    private array $ownVoiceGroupIds;

    /**
     * Die Sitzung kommt von außen - siehe
     * tests/Unit/Policies/PoliciesReceiveTheSessionTest.
     *
     * Sie stand hier zuvor an jeder Methode als erster Parameter, während die
     * vier übrigen Policies sie im Konstruktor nehmen. Zwei Wege zur selben
     * Angabe: Beim Lesen musste man erst nachsehen, welcher hier gerade gilt,
     * und als Parameter ließ sich eine andere Sitzung hineingeben als die der
     * anfragenden Person. Mit dem Konstruktor kann die Prüfung gar nicht mehr
     * für jemand anderen antworten.
     *
     * @param array<string, mixed> $session
     */
    public function __construct(array $session)
    {
        // Auf Wahrheitswert prüfen, nicht strikt auf true - Middleware und
        // Controller lesen dieselben Sitzungsschlüssel ebenfalls nur truthy.
        $this->canEditUsers = (bool) ($session['can_edit_users'] ?? false);
        $this->canManageOwnVoiceGroup = (bool) ($session['can_manage_own_voice_group'] ?? false);
        $this->canManageProjectMembers = (bool) ($session['can_manage_project_members'] ?? false);
        $this->actorLevel = (int) ($session['role_level'] ?? 0);
        $this->ownVoiceGroupIds = array_values(array_map(
            static fn ($id): int => (int) $id,
            (array) ($session['voice_group_ids'] ?? [])
        ));
    }

    /**
     * True when the session may open the edit form for the target at all.
     */
    public function canEdit(User $target): bool
    {
        // Das Formular öffnet, wer darin irgendetwas schreiben darf. Die
        // Projektzuordnung hängt im selben Formular - ohne diesen zweiten Weg
        // blieben Projekte für einen reinen Projektmitglieder-Verwalter über die
        // Mitgliederliste unerreichbar, obwohl update() den POST akzeptiert.
        return $this->canEditProfile($target)
            || $this->canEditProjects($target);
    }

    /**
     * True when the session may write the member's project assignment - the only
     * field a pure project member manager gets to change on this form.
     *
     * Vom archivierten Ziel abgesehen gelten die Basisprüfungen hier nicht: Die
     * Rollenhierarchie sperrt die Zuordnung nicht, siehe den Klassenkommentar
     * oben. Der Archiv-Riegel bleibt, und zwar aus einem eigenen Grund -
     * ProjectPersistence::addProjectMember() holt ein archiviertes Konto beim
     * Zuordnen zurück, und dieser Weg gehört in die Mitgliederverwaltung und
     * nicht in ein Formular, das sich für ein archiviertes Mitglied gar nicht
     * öffnet.
     *
     * UserController::update() hat diese Stufe zuvor selbst aus der Session
     * zusammengesetzt und dabei auch den Archiv-Riegel übersprungen: das Formular
     * eines archivierten Mitglieds ließ sich zwar nicht mehr öffnen, ein direkt
     * abgesetzter POST auf /users/{id} schrieb die Projektzuordnung aber
     * weiterhin.
     *
     * Allein can_manage_project_members, und das ist eine Entscheidung: Bis Lauf
     * 59 stand can_edit_users als rohes Sitzungsflag neben dieser Stufe in
     * UserController::update() (`$canEditGlobal || $canEditProjects`), während
     * das Formular die Kästchen nach einer dritten Regel freischaltete. Drei
     * Quellen für eine Frage, und sie liefen auseinander: Eine Stimmvertretung
     * sah die Kästchen, klickte sie an und bekam "Mitglied erfolgreich
     * aktualisiert." zu lesen - geschrieben wurde die Zuordnung nicht.
     *
     * Aufgelöst ist das zugunsten dieser Stufe, nicht zugunsten des Flags: Jedes
     * Recht bedeutet dann genau eine Sache, und es ist das, was der
     * Klassenkommentar oben von Anfang an beschreibt. Für die ausgelieferten
     * Rollen kostet es nichts - Admin und Vorstand halten beide Rechte ohnehin.
     * Eine eigens gebaute Rolle mit "Mitglieder editieren erlauben", aber ohne
     * "Projektmitglieder verwalten", verliert das Feld; wer es dort braucht,
     * hängt der Rolle das zweite Recht an, statt das erste zu verbreitern.
     */
    public function canEditProjects(User $target): bool
    {
        return (int) ($target->is_active ?? 0) === 1
            && $this->canManageProjectMembers;
    }

    /**
     * Dieselbe Frage für ein Mitglied, das es noch nicht gibt: Darf das
     * Anlegen-Formular die Projektzuordnung mitschicken?
     *
     * Ohne Ziel und deshalb ohne die Basisprüfungen - ein frisch angelegtes
     * Mitglied ist aktiv und hält keine Rolle über dem eigenen Level, weil
     * capRoleIdsToActorLevel() in UserController::create() die Auswahl vorher
     * deckelt. Bleibt genau das Recht.
     *
     * Eigene Methode, weil create() die Regel bis Lauf 59 als vierte Fassung
     * selbst aus der Sitzung zusammensetzte (`can_edit_users or
     * can_manage_project_members`) - mit dem Kommentar "Dieselbe Rechteregel wie
     * in update()" darüber, was sie schon damals nicht war.
     */
    public function canAssignProjectsOnCreate(): bool
    {
        return $this->canManageProjectMembers;
    }

    /**
     * True when the session may write the member's own data - first name, last name,
     * roles and voice groups. Die E-Mail-Adresse hängt an canEditEmail().
     */
    public function canEditProfile(User $target): bool
    {
        return $this->passesBaseGuards($target)
            && $this->holdsProfileRight($target);
    }

    /**
     * True when the session may write the member's e-mail address.
     *
     * Nur bei niedrigerer Stufe: Die Adresse ist der Schlüssel zum Konto. Wer sie
     * ändert, setzt danach per "Passwort vergessen" das Passwort und hat das
     * Konto samt dessen Rechten. Gleichrangige dürfen einander weiter vertreten
     * (canEditProfile()), die Adresse des anderen ändern sie nicht.
     */
    public function canEditEmail(User $target): bool
    {
        return $this->passesBaseGuards($target)
            && $this->canEditUsers
            && $this->highestLevelOf($target) < $this->actorLevel;
    }

    /**
     * True when the session may archive the member - or bring an archived one back.
     *
     * Die einzige Stufe, die den archivierten Zustand des Ziels nicht prüft:
     * Archivieren und Wiederherstellen sind dieselbe Befugnis in zwei Richtungen,
     * und wäre nur das Archivieren erlaubt, könnte eine Stimmvertretung ein
     * Mitglied stilllegen, aber nicht mehr zurückholen. Hierarchie und Rechte
     * gelten wie beim Bearbeiten - canEditProfile() ist genau diese Stufe plus
     * "Ziel ist aktiv".
     */
    public function canArchive(User $target): bool
    {
        return !$this->targetOutranksActor($target)
            && $this->holdsProfileRight($target);
    }

    /**
     * True when the member may be archived - die Befugnis aus canArchive() und
     * zusätzlich: das Mitglied hält keine Rolle mehr über dem niedrigsten
     * vergebenen Level.
     *
     * Die Rollen kommen also zuerst herunter, dann das Konto ins Archiv. Der
     * Grund steht an der Wiederherstellung: ein archiviertes Konto lässt sich
     * nicht nur über die Mitgliederverwaltung zurückholen, sondern auch dadurch,
     * dass jemand es einem Projekt zuordnet - ProjectPersistence::addProjectMember()
     * aktiviert es dabei. Dieser Weg kennt die Rollenhierarchie bewusst nicht
     * (siehe ProjectMemberPolicy). Trägt das Konto beim Archivieren nichts
     * Erhöhtes mehr, kann er auch nichts Erhöhtes zurückholen.
     *
     * Die Regel greift nur in diese eine Richtung. canArchive() bleibt, wie es
     * war, sonst steckten die vor dieser Regel archivierten Mitglieder mit ihren
     * alten Rollen für immer im Archiv fest.
     *
     * @param int $minimalRoleLevel niedrigstes vergebenes Level, siehe Role::minimalHierarchyLevel()
     */
    public function canDeactivate(User $target, int $minimalRoleLevel): bool
    {
        return $this->canArchive($target)
            && !$this->holdsRoleAboveMinimalLevel($target, $minimalRoleLevel);
    }

    /**
     * Hält das Mitglied mindestens eine Rolle über dem niedrigsten vergebenen
     * Level? Mehrere Rollen auf dem Minimum sind unauffällig - entscheidend ist
     * nicht ihre Anzahl, sondern ob eine davon darüber hinausreicht.
     *
     * Öffentlich, weil die Mitgliederliste denselben Grund braucht, um den
     * Archivieren-Knopf gar nicht erst anzubieten.
     */
    public function holdsRoleAboveMinimalLevel(User $target, int $minimalRoleLevel): bool
    {
        foreach ($target->roles as $role) {
            if ((int) ($role->hierarchy_level ?? 0) > $minimalRoleLevel) {
                return true;
            }
        }

        return false;
    }

    /**
     * Guards that apply to every path: archived members stay untouchable, and so
     * does anybody who outranks the acting session.
     *
     * Ein Ziel ohne geladenen `is_active`-Wert gilt als nicht bearbeitbar. Zuvor
     * stand hier `?? 1`, also "im Zweifel aktiv": eine Abfrage mit engerer
     * Spaltenauswahl - wie sie das Projekt an mehreren Stellen bewusst einsetzt,
     * siehe User::LIST_COLUMNS und ProjectQuery::getUserVoiceGroupIds() - hätte
     * damit still das Bearbeiten eines archivierten Mitglieds freigegeben. Die
     * Abweisung ist die sichere Richtung und fällt sofort auf, weil sie den
     * erlaubten Fall sperrt statt den verbotenen zu öffnen.
     */
    private function passesBaseGuards(User $target): bool
    {
        if ((int) ($target->is_active ?? 0) !== 1) {
            return false;
        }

        return !$this->targetOutranksActor($target);
    }

    /**
     * The two rights that unlock the member's own data.
     */
    private function holdsProfileRight(User $target): bool
    {
        if ($this->canEditUsers) {
            return true;
        }

        // Die gemeinsame Stimmgruppe allein reicht nicht: UserController::update(),
        // ::deactivate() und ::invite() verlangen zusätzlich can_manage_own_voice_group.
        // Da jede angemeldete Sitzung voice_group_ids trägt, hätte die Policy sonst
        // jedem Mitgliederverwalter ohne dieses Recht einen Bearbeiten-Link auf die
        // eigene Stimmgruppe angeboten, den das Speichern mit "Keine Berechtigung"
        // quittiert.
        if (!$this->canManageOwnVoiceGroup) {
            return false;
        }

        if ($this->ownVoiceGroupIds === []) {
            return false;
        }

        $targetGroupIds = $target->voiceGroups
            ->pluck('id')
            ->map(static fn ($id): int => (int) $id)
            ->all();

        return array_intersect($this->ownVoiceGroupIds, $targetGroupIds) !== [];
    }

    /**
     * True when the target holds a role that outranks the acting session's own level.
     *
     * Die Rollen sind an beiden Aufrufstellen (UserQuery::getActiveUsers() und
     * ::findIncludingArchived()) bereits eager-geladen, ein zusätzlicher Query
     * entsteht nicht.
     *
     * Öffentlich, weil UserController dieselbe Regel auf Wegen braucht, die diese
     * Policy sonst nicht berührt - Einladen und Deaktivieren. Sie dort ein zweites
     * Mal aus der Sitzung zusammenzusetzen hieße: zwei Quellen für dieselbe
     * Sicherheitsregel, die beim nächsten Eingriff auseinanderlaufen.
     */
    public function targetOutranksActor(User $target): bool
    {
        return $this->highestLevelOf($target) > $this->actorLevel;
    }

    private function highestLevelOf(User $target): int
    {
        $targetLevel = 0;

        foreach ($target->roles as $role) {
            $level = (int) ($role->hierarchy_level ?? 0);
            if ($level > $targetLevel) {
                $targetLevel = $level;
            }
        }

        return $targetLevel;
    }
}
