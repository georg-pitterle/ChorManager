<?php

declare(strict_types=1);

namespace App\Services\Oidc;

use RuntimeException;

/**
 * Ein Fehler, den das Protokoll benennt.
 *
 * Nach außen geht nur der knappe Standardschlüssel (`invalid_grant`,
 * `invalid_client`, `invalid_request`) - der Grund gehört ins Log und nicht in
 * die Antwort, sonst verrät sie einem Angreifer, an welcher Stelle er gerade
 * danebenlag. Genau dafür trägt die Ausnahme beides getrennt.
 */
class OidcProtocolException extends RuntimeException
{
    public function __construct(
        private readonly string $errorCode,
        string $reason,
        private readonly int $status = 400
    ) {
        parent::__construct($reason);
    }

    public function errorCode(): string
    {
        return $this->errorCode;
    }

    /**
     * Der Grund für das Log. Nie Teil der Antwort.
     */
    public function reason(): string
    {
        return $this->getMessage();
    }

    public function status(): int
    {
        return $this->status;
    }
}
