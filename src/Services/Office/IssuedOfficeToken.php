<?php

declare(strict_types=1);

namespace App\Services\Office;

use Carbon\CarbonImmutable;

/** Ein frisch ausgestelltes Token - der Klartext existiert nur in diesem Objekt. */
final class IssuedOfficeToken
{
    public function __construct(
        public readonly string $plain,
        public readonly CarbonImmutable $expiresAt
    ) {
    }

    /** `access_token_ttl` nach WOPI: Ablaufzeitpunkt in Millisekunden seit 1970. */
    public function ttlMilliseconds(): int
    {
        return $this->expiresAt->getTimestamp() * 1000;
    }
}
