<?php

declare(strict_types=1);

namespace App\Services\Audience;

/** Abgelehnte Freigabe-Zeile; die Meldung ist für die Oberfläche gedacht. */
final class InvalidAudienceFilterException extends \InvalidArgumentException
{
}
