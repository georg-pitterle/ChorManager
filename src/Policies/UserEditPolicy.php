<?php

declare(strict_types=1);

namespace App\Policies;

use App\Models\User;

/**
 * Decides whether the current session may edit a given member.
 *
 * Zwei Stufen, weil das Bearbeiten-Formular zwei Zwecke bedient:
 *  - canEdit(): darf das Formular ueberhaupt geoeffnet werden? Neben
 *    can_edit_users und can_manage_own_voice_group (eigene Stimmgruppe) reicht
 *    dafuer auch can_manage_project_members, dessen Traeger die Projektzuordnung
 *    ueber genau dieses Formular pflegt.
 *  - canEditProfile(): duerfen Name, E-Mail, Rollen und Stimmgruppen geschrieben
 *    werden? Das bleibt can_edit_users und der eigenen Stimmgruppe vorbehalten.
 *    Ein reiner Projektmitglieder-Verwalter aendert nur die Projektzuordnung -
 *    eine fremde E-Mail-Adresse plus Passwort-Reset waere sonst ein
 *    Uebernahmepfad auf das Zielkonto.
 *
 * Ueber allen Wegen steht die Rollenhierarchie: ein Mitglied, dessen hoechste
 * Rolle ueber dem eigenen Level liegt, bleibt unantastbar. UserController::update()
 * und ::deactivate() weisen solche Ziele ohnehin ab - ohne dieselbe Regel hier
 * haette die Mitgliederliste einen Bearbeiten-Link angeboten, der beim Klick
 * zwangslaeufig in einer 403-Meldung endet.
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
        if (!$this->passesBaseGuards($session, $target)) {
            return false;
        }

        if ($this->holdsProfileRight($session, $target)) {
            return true;
        }

        // Die Projektzuordnung haengt im selben Formular. Ohne diesen Weg blieben
        // Projekte fuer einen reinen Projektmitglieder-Verwalter ueber die
        // Mitgliederliste unerreichbar, obwohl update() den POST akzeptiert.
        return !empty($session['can_manage_project_members']);
    }

    /**
     * True when the session may write the member's own data - first name, last name,
     * e-mail, roles and voice groups.
     *
     * @param array<string, mixed> $session
     */
    public function canEditProfile(array $session, User $target): bool
    {
        return $this->passesBaseGuards($session, $target)
            && $this->holdsProfileRight($session, $target);
    }

    /**
     * Guards that apply to every path: archived members stay untouchable, and so
     * does anybody who outranks the acting session.
     *
     * @param array<string, mixed> $session
     */
    private function passesBaseGuards(array $session, User $target): bool
    {
        if ((int) ($target->is_active ?? 1) !== 1) {
            return false;
        }

        return !$this->outranksActor($session, $target);
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
        // ::deactivate() und ::invite() verlangen zusaetzlich can_manage_own_voice_group.
        // Da jede angemeldete Sitzung voice_group_ids traegt, haette die Policy sonst
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
     * ::findById()) bereits eager-geladen, ein zusaetzlicher Query entsteht nicht.
     *
     * @param array<string, mixed> $session
     */
    private function outranksActor(array $session, User $target): bool
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
