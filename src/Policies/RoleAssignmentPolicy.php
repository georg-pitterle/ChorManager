<?php

declare(strict_types=1);

namespace App\Policies;

use App\Models\Role;

/**
 * Welche Rollen die Sitzung vergeben darf.
 *
 * Zwei Bedingungen: Die Rolle liegt nicht über der eigenen Stufe, und jedes
 * Recht der Rolle hält der Handelnde selbst. Die zweite kam später dazu - die
 * Stufe allein ließ eine gleichrangige Rolle mit fremden Rechten durch, und
 * zwei Vorstände mit verschiedenen Zuständigkeiten konnten einander so Rechte
 * zuschanzen, die keiner von beiden hätte vergeben dürfen.
 *
 * Abgeleitete Rechte (Role::IMPLIED_PERMISSIONS) zählen wie eigene: Die Sitzung
 * trägt sie bereits (SessionAuthService::setAuthenticatedUser()).
 */
final class RoleAssignmentPolicy
{
    private int $actorLevel;

    /** @var array<string, mixed> */
    private array $session;

    /**
     * @param array<string, mixed> $session
     */
    public function __construct(array $session)
    {
        $this->session = $session;
        $this->actorLevel = (int) ($session['role_level'] ?? 0);
    }

    public function canAssign(Role $role): bool
    {
        if ((int) ($role->hierarchy_level ?? 0) > $this->actorLevel) {
            return false;
        }

        foreach (Role::PERMISSIONS as $permission) {
            if ((bool) ($role->{$permission} ?? false) && empty($this->session[$permission])) {
                return false;
            }
        }

        return true;
    }

    /**
     * @param iterable<Role> $roles
     * @return list<int>
     */
    public function assignableIds(iterable $roles): array
    {
        $ids = [];
        foreach ($roles as $role) {
            if ($this->canAssign($role)) {
                $ids[] = (int) $role->id;
            }
        }

        return $ids;
    }
}
