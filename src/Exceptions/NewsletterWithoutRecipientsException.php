<?php

declare(strict_types=1);

namespace App\Exceptions;

use RuntimeException;

/**
 * Ein Newsletter ohne aufgelöste Empfänger darf nicht versendet werden.
 */
class NewsletterWithoutRecipientsException extends RuntimeException
{
    public const MESSAGE = 'Newsletter hat keine Empfänger.';

    public function __construct(string $message = self::MESSAGE)
    {
        parent::__construct($message);
    }
}
