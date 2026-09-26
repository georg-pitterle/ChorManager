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

    /**
     * Vorsilbe der abgeleiteten Kennung. Steht hier und nicht in der Verwaltung:
     * Wer die Form ändert, muss auch die Sperre in isDerivedSubject() ändern, und
     * das fällt nur auf, wenn beides nebeneinander liegt.
     */
    public const DERIVED_PREFIX = 'cm-';

    public static function subjectFor(User $user): string
    {
        $external = trim((string) ($user->external_uid ?? ''));

        return $external !== '' ? $external : self::DERIVED_PREFIX . (int) $user->id;
    }

    /**
     * Trägt diese Kennung genau die Form, die subjectFor() selbst vergibt?
     *
     * Von Hand eingetragen wäre sie eine Falle: Mitglied 4711 ohne eigene
     * Kennung weist sich als `cm-4711` aus, und wer dieselbe Zeichenfolge bei
     * einem anderen Mitglied hinterlegt, schickt beide auf dasselbe Konto der
     * angeschlossenen Anwendung. Die Dublettenprüfung der Verwaltung sieht das
     * nicht - sie vergleicht nur gespeicherte Kennungen, und die abgeleitete
     * steht nirgends.
     *
     * Ohne Rücksicht auf Groß- und Kleinschreibung: `CM-4711` kann mit keinem
     * abgeleiteten `sub` zusammenfallen, taugt aber auch zu nichts - die Form
     * gehört ChorManager. "cm-georg" bleibt erlaubt, dort steht keine Zahl.
     */
    public static function isDerivedSubject(string $candidate): bool
    {
        $pattern = '/^' . preg_quote(self::DERIVED_PREFIX, '/') . '\d+$/i';

        return preg_match($pattern, trim($candidate)) === 1;
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
