<?php

declare(strict_types=1);

namespace App\Logging;

/**
 * Haelt die Kenndaten des laufenden Requests fuer das Logging.
 *
 * Eine Instanz pro Request, veraenderlich: Die Middleware fuellt sie zu Beginn,
 * die Benutzerkennung kommt erst nach der Authentifizierung dazu.
 */
final class RequestContext
{
    /** @var array<string, scalar> */
    private array $data = [];

    /**
     * @param array<string, scalar> $data
     */
    public function assign(array $data): void
    {
        $this->data = array_merge($this->data, $data);
    }

    public function setUserId(?int $userId): void
    {
        if ($userId === null) {
            unset($this->data['user_id']);

            return;
        }

        $this->data['user_id'] = $userId;
    }

    /**
     * @return array<string, scalar>
     */
    public function all(): array
    {
        return $this->data;
    }
}
