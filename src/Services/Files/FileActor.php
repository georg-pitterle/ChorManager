<?php

declare(strict_types=1);

namespace App\Services\Files;

use App\Models\Role;
use App\Models\User;

/**
 * Wer gerade in der Dateiverwaltung handelt. Aus der Sitzung gebaut, damit die
 * Services ohne `$_SESSION` auskommen und sich ohne Sitzung testen lassen.
 */
final class FileActor
{
    public function __construct(
        public readonly int $userId,
        public readonly bool $isFileAdmin
    ) {
    }

    /**
     * @param array<string, mixed> $session
     */
    public static function fromSession(array $session): ?self
    {
        $userId = (int) ($session['user_id'] ?? 0);
        if ($userId <= 0) {
            return null;
        }

        return new self($userId, !empty($session['can_manage_files']));
    }

    /**
     * Für Aufrufe ohne Sitzung (Collabora): Das Datei-Admin-Recht kommt aus den
     * aktuellen Rollen, nicht aus einem Stand vom Login. Wer deaktiviert ist,
     * bekommt keinen Actor.
     */
    public static function forUser(User $user): ?self
    {
        if (!(bool) $user->is_active) {
            return null;
        }

        $isFileAdmin = $user->roles->contains(
            static fn (Role $role): bool => (bool) ($role->can_manage_files ?? false)
        );

        return new self((int) $user->id, $isFileAdmin);
    }
}
