<?php

declare(strict_types=1);

namespace App\Services\Files;

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
}
