<?php

declare(strict_types=1);

namespace App\Services\Oidc;

use App\Models\Role;
use App\Models\User;
use RuntimeException;

/**
 * Die Verwaltungsvorgänge hinter `bin/oidc_admin.php`.
 *
 * Die Logik liegt hier und nicht im Skript: Das Skript ist nur Ein- und
 * Ausgabe, und geprüft wird auf dieser Ebene.
 *
 * In der ersten Ausbaustufe gibt es bewusst keine Oberfläche. Die Zuordnung
 * Rolle zu Gruppe und die Kennung der Bestandskonten sind Einrichtungsschritte,
 * die einmal beim Anschluss anfallen und danach selten.
 */
class OidcAdminService
{
    /**
     * Alle Rollen mit ihrer Gruppe - auch die ohne. Nur so ist sichtbar, was
     * gerade nicht in die angeschlossene Anwendung geht.
     *
     * @return list<array{name:string, group:string|null}>
     */
    public function listRoleGroups(): array
    {
        $rows = [];

        foreach (Role::query()->orderBy('name')->get() as $role) {
            $group = trim((string) ($role->external_group ?? ''));
            $rows[] = ['name' => (string) $role->name, 'group' => $group === '' ? null : $group];
        }

        return $rows;
    }

    /**
     * @throws RuntimeException wenn es die Rolle nicht gibt.
     */
    public function setRoleGroup(string $roleName, ?string $group): Role
    {
        $role = Role::query()->where('name', $roleName)->first();
        if ($role === null) {
            throw new RuntimeException(sprintf('Die Rolle "%s" gibt es nicht.', $roleName));
        }

        $normalized = $group === null ? null : trim($group);
        if ($normalized === '') {
            $normalized = null;
        }

        if ($normalized !== null && !self::isValidGroupName($normalized)) {
            throw new RuntimeException(sprintf(
                'Der Gruppenname "%s" ist unbrauchbar. Erlaubt sind Buchstaben, Ziffern, '
                    . 'Bindestrich, Unterstrich und Punkt.',
                $normalized
            ));
        }

        $role->external_group = $normalized;
        $role->save();

        return $role;
    }

    /**
     * @return list<array{email:string, name:string, uid:string}>
     */
    public function listUsersWithExternalUid(): array
    {
        $rows = [];

        $users = User::query()
            ->whereNotNull('external_uid')
            ->where('external_uid', '!=', '')
            ->orderBy('email')
            ->get();

        foreach ($users as $user) {
            $rows[] = [
                'email' => (string) $user->email,
                'name' => trim((string) $user->first_name . ' ' . (string) $user->last_name),
                'uid' => (string) $user->external_uid,
            ];
        }

        return $rows;
    }

    /**
     * Trägt die Kennung eines Bestandskontos ein.
     *
     * Die Eindeutigkeit erzwingt schon der Index - die Meldung hier ist
     * trotzdem nötig, damit aus einem Datenbankfehler eine verständliche
     * Auskunft wird.
     *
     * @throws RuntimeException
     */
    public function setExternalUid(string $email, string $uid): User
    {
        $uid = trim($uid);
        if ($uid === '') {
            throw new RuntimeException('Die Kennung darf nicht leer sein.');
        }

        if (!self::isValidExternalUid($uid)) {
            throw new RuntimeException(sprintf(
                'Die Kennung "%s" ist unbrauchbar. Erlaubt sind Buchstaben, Ziffern, '
                    . 'Bindestrich, Unterstrich, Punkt und @.',
                $uid
            ));
        }

        $user = $this->requireUser($email);

        $owner = User::query()->where('external_uid', $uid)->first();
        if ($owner !== null && (int) $owner->id !== (int) $user->id) {
            throw new RuntimeException(sprintf(
                'Die Kennung "%s" gehört bereits %s.',
                $uid,
                (string) $owner->email
            ));
        }

        $user->external_uid = $uid;
        $user->save();

        return $user;
    }

    public function unsetExternalUid(string $email): User
    {
        $user = $this->requireUser($email);
        $user->external_uid = null;
        $user->save();

        return $user;
    }

    private function requireUser(string $email): User
    {
        $user = User::query()->where('email', $email)->first();
        if ($user === null) {
            throw new RuntimeException(sprintf('Es gibt kein Mitglied mit der Adresse "%s".', $email));
        }

        return $user;
    }

    private static function isValidGroupName(string $group): bool
    {
        return strlen($group) <= 64 && preg_match('/^[A-Za-z0-9._-]+$/', $group) === 1;
    }

    private static function isValidExternalUid(string $uid): bool
    {
        return strlen($uid) <= 64 && preg_match('/^[A-Za-z0-9._@-]+$/', $uid) === 1;
    }
}
