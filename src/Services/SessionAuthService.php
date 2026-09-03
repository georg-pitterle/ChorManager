<?php

declare(strict_types=1);

namespace App\Services;

use App\Logging\RequestContext;
use App\Models\Role;
use App\Models\User;

class SessionAuthService
{
    private NameFormatterService $nameFormatter;
    private RequestContext $requestContext;

    public function __construct(NameFormatterService $nameFormatter, RequestContext $requestContext)
    {
        $this->nameFormatter = $nameFormatter;
        $this->requestContext = $requestContext;
    }

    public function setAuthenticatedUser(User $user): void
    {
        $_SESSION['user_id'] = (int) $user->id;
        $_SESSION['user_name'] = $this->nameFormatter->formatPerson($user);
        // Einziger Ort, an dem die Session-Benutzerkennung gesetzt wird (Login,
        // Remember-Me-Wiederherstellung, Rechte-Refresh). Der Logging-Kontext wird
        // hier mitgezogen, damit alle Folge-Logzeilen die user_id tragen.
        $this->requestContext->setUserId((int) $user->id);

        $granted = $this->grantedPermissions($user);

        // Jeder Schlüssel wird gesetzt, auch der ohne Recht: Bei einem
        // Rechte-Wechsel innerhalb derselben Sitzung bliebe sonst der alte Wert
        // stehen, und das Recht wirkte weiter.
        foreach (Role::PERMISSIONS as $permission) {
            $_SESSION[$permission] = isset($granted[$permission]);
        }

        $_SESSION['role_level'] = $this->highestHierarchyLevel($user);
        $_SESSION['voice_group_ids'] = $user->voiceGroups->pluck('id')->toArray();

        if (!isset($_SESSION['auth_epoch'])) {
            $_SESSION['auth_epoch'] = time();
        }
    }

    /**
     * Die Rechte aller Rollen des Mitglieds, zusammengelegt.
     *
     * Mehrere Rollen ergänzen einander: Ein Recht gilt, sobald irgendeine Rolle
     * es trägt. Ein Vollrecht zieht sein kleineres Recht mit, siehe
     * `Role::IMPLIED_PERMISSIONS`.
     *
     * @return array<string, true>
     */
    private function grantedPermissions(User $user): array
    {
        $granted = [];

        foreach ($user->roles as $role) {
            foreach (Role::PERMISSIONS as $permission) {
                // Das `?? false` deckt eine Rolle ab, deren Spalte noch fehlt -
                // etwa zwischen zwei Migrationen.
                if (!($role->{$permission} ?? false)) {
                    continue;
                }

                $granted[$permission] = true;

                foreach (Role::IMPLIED_PERMISSIONS[$permission] ?? [] as $implied) {
                    $granted[$implied] = true;
                }
            }
        }

        return $granted;
    }

    /**
     * Das höchste Hierarchie-Level aller Rollen.
     *
     * Das Level vergibt bewusst kein Recht. Es entscheidet ausschließlich
     * darüber, wessen Zuordnungen ein Mitglied noch ändern darf (siehe
     * UserController und RoleController) - jedes Recht muss einzeln an der Rolle
     * gesetzt sein.
     */
    private function highestHierarchyLevel(User $user): int
    {
        $highest = 0;

        foreach ($user->roles as $role) {
            $level = (int) $role->hierarchy_level;
            if ($level > $highest) {
                $highest = $level;
            }
        }

        return $highest;
    }

    public function clearSession(): void
    {
        $_SESSION = [];

        if (ini_get('session.use_cookies')) {
            $params = session_get_cookie_params();
            setcookie(
                session_name(),
                '',
                [
                    'expires' => time() - 42000,
                    'path' => $params['path'],
                    'domain' => $params['domain'],
                    'secure' => (bool) $params['secure'],
                    'httponly' => (bool) $params['httponly'],
                    'samesite' => $params['samesite'] ?? 'Lax'
                ]
            );
        }

        if (session_status() === PHP_SESSION_ACTIVE) {
            session_destroy();
        }
    }
}
