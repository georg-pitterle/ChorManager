<?php

declare(strict_types=1);

namespace App\Exceptions;

use RuntimeException;

/**
 * Keine der angegebenen Zielgruppen-Quellen existiert noch. Ein Termin ohne
 * Quellen gilt für alle Mitglieder - die Zielgruppe würde sich also
 * stillschweigend verbreitern, statt zu scheitern.
 */
class InvalidAudienceSourcesException extends RuntimeException
{
    public const MESSAGE = 'Die gewählte Zielgruppe existiert nicht mehr. Bitte neu auswählen.';

    public function __construct(string $message = self::MESSAGE)
    {
        parent::__construct($message);
    }
}
