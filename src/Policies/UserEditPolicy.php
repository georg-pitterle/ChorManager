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
 * Über allen Wegen steht die Rollenhierarchie: ein Mitglied, dessen höchste
 * Rolle über dem eigenen Level liegt, bleibt unantastbar. UserController::update()
 * und ::deactivate() weisen solche Ziele ohnehin ab - ohne dieselbe Regel hier
 * hätte die Mitgliederliste einen Bearbeiten-Link angeboten, der beim Klick
 * zwangsläufig in einer 403-Meldung endet.
 */
class UserEditPolicy
{
    /**
     * True when the session may open the edit form for the target at all.
     *
     * @param array<string, mixed> $session
     */
    public function canEdit(array $session, User $target): bool
    {
        // Das Formular öffnet, wer darin irgendetwas schreiben darf. Die
        // Projektzuordnung hängt im selben Formular - ohne diesen zweiten Weg
        // blieben Projekte für einen reinen Projektmitglieder-Verwalter über die
        // Mitgliederliste unerreichbar, obwohl update() den POST akzeptiert.
        return $this->canEditProfile($session, $target)
            || $this->canEditProjects($session, $target);
    }

    /**
     * True when the session may write the member's project assignment - the only
     * field a pure project member manager gets to change on this form.
     *
     * Die Basisprüfungen gelten hier genauso wie auf den anderen Stufen.
     * UserController::update() hat diese Stufe zuvor selbst aus der Session
     * zusammengesetzt und dabei die Basisprüfungen übersprungen: das Formular
     * eines archivierten Mitglieds ließ sich zwar nicht mehr öffnen, ein direkt
     * abgesetzter POST auf /users/{id} schrieb die Projektzuordnung aber
     * weiterhin.
     *
     * @param array<string, mixed> $session
     */
    public function canEditProjects(array $session, User $target): bool
    {
        return $this->passesBaseGuards($session, $target)
            && !empty($session['can_manage_project_members']);
    }

    /**
     * True when the session may write the member's own data - first name, last name,
     * roles and voice groups. Die E-Mail-Adresse hängt an canEditEmail().
     *
     * @param array<string, mixed> $session
     */
    public function canEditProfile(array $session, User $target): bool
    {
        return $this->passesBaseGuards($session, $target)
            && $this->holdsProfileRight($session, $target);
    }

    /**
     * True when the session may write the member's e-mail address.
     *
     * @param array<string, mixed> $session
     */
    public function canEditEmail(array $session, User $target): bool
    {
        return $this->passesBaseGuards($session, $target)
            && !empty($session['can_edit_users']);
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
     *
     * @param array<string, mixed> $session
     */
    public function canArchive(array $session, User $target): bool
    {
        return !$this->targetOutranksActor($session, $target)
            && $this->holdsProfileRight($session, $target);
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
     *
     * @param array<string, mixed> $session
     */
    private function passesBaseGuards(array $session, User $target): bool
    {
        if ((int) ($target->is_active ?? 0) !== 1) {
            return false;
        }

        return !$this->targetOutranksActor($session, $target);
    }

    /**
     * The two rights that unlock the member's own data.
     *
     * @param array<string, mixed> $session
     */
    private function holdsProfileRight(array $session, User $target): bool
    {
        if (!empty($session['can_edit_users'])) {
            return true;
        }

        // Die gemeinsame Stimmgruppe allein reicht nicht: UserController::update(),
        // ::deactivate() und ::invite() verlangen zusätzlich can_manage_own_voice_group.
        // Da jede angemeldete Sitzung voice_group_ids trägt, hätte die Policy sonst
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
     * ::findById()) bereits eager-geladen, ein zusätzlicher Query entsteht nicht.
     *
     * Öffentlich, weil UserController dieselbe Regel auf Wegen braucht, die diese
     * Policy sonst nicht berührt - Einladen und Deaktivieren. Sie dort ein zweites
     * Mal aus der Sitzung zusammenzusetzen hieße: zwei Quellen für dieselbe
     * Sicherheitsregel, die beim nächsten Eingriff auseinanderlaufen.
     *
     * @param array<string, mixed> $session
     */
    public function targetOutranksActor(array $session, User $target): bool
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
