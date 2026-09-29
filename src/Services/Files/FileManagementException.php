<?php

declare(strict_types=1);

namespace App\Services\Files;

/**
 * Abgewiesene Aktion der Dateiverwaltung. Die Meldung ist deutsch und für die
 * Oberfläche gedacht, `status` der passende HTTP-Status.
 */
final class FileManagementException extends \RuntimeException
{
    public function __construct(string $message, public readonly int $status = 422)
    {
        parent::__construct($message, $status);
    }

    public static function forbidden(): self
    {
        return new self('Dafür fehlt die Berechtigung.', 403);
    }

    public static function notFound(): self
    {
        return new self('Nicht gefunden.', 404);
    }
}
