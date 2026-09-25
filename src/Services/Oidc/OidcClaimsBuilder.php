<?php

declare(strict_types=1);

namespace App\Services\Oidc;

use App\Models\User;

/**
 * Übersetzt ein Mitglied in die Ansprüche, die eine angeschlossene Anwendung
 * auswertet.
 *
 * Zwei Festlegungen stecken hier drin:
 *
 * - `sub` ist `users.external_uid`, sonst die abgeleitete Form `cm-<id>`. In
 *   `user_oidc` wird "unique user id" abgeschaltet, dann ist `sub` unverändert
 *   die dortige Benutzer-Kennung. Bestandskonten bekommen ihre vorhandene
 *   Kennung von Hand eingetragen, alle anderen die stabile abgeleitete Form.
 * - `groups` speist sich ausschließlich aus `roles.external_group`. Eine Rolle
 *   ohne Zuordnung geht gar nicht hinaus - kein Rückfall auf den Rollennamen,
 *   sonst zerlegte ein Umbenennen in ChorManager die dortigen Freigaben.
 *
 * Stimmgruppen und die `can_*`-Rechte bleiben draußen: Die andere Anwendung
 * kennt keine ChorManager-Rechte, sie braucht Gruppen für Freigaben.
 */
class OidcClaimsBuilder
{
    public const SCOPE_PROFILE = 'profile';
    public const SCOPE_EMAIL = 'email';
    public const SCOPE_GROUPS = 'groups';

    public static function subjectFor(User $user): string
    {
        $external = trim((string) ($user->external_uid ?? ''));

        return $external !== '' ? $external : 'cm-' . (int) $user->id;
    }

    /**
     * @return array<string, mixed>
     */
    public function build(User $user, string $scope): array
    {
        $scopes = self::splitScope($scope);
        $subject = self::subjectFor($user);

        $claims = ['sub' => $subject];

        if (in_array(self::SCOPE_PROFILE, $scopes, true)) {
            $claims['preferred_username'] = $subject;
            $claims['name'] = trim(
                trim((string) $user->first_name) . ' ' . trim((string) $user->last_name)
            );
        }

        if (in_array(self::SCOPE_EMAIL, $scopes, true)) {
            $claims['email'] = (string) $user->email;
            // Die Adresse ist die Anmeldekennung in ChorManager und wurde bei
            // der Einladung bestätigt.
            $claims['email_verified'] = true;
        }

        if (in_array(self::SCOPE_GROUPS, $scopes, true)) {
            $claims['groups'] = $this->groupsFor($user);
        }

        return $claims;
    }

    /**
     * @return list<string>
     */
    public function groupsFor(User $user): array
    {
        $groups = [];

        foreach ($user->roles as $role) {
            $group = trim((string) ($role->external_group ?? ''));
            if ($group === '') {
                continue;
            }

            $groups[$group] = true;
        }

        return array_keys($groups);
    }

    /**
     * @return list<string>
     */
    public static function splitScope(string $scope): array
    {
        $parts = preg_split('/\s+/', trim($scope)) ?: [];

        return array_values(array_filter($parts, static fn(string $part): bool => $part !== ''));
    }
}
