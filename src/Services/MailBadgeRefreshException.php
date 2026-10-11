<?php

declare(strict_types=1);

namespace App\Services;

use RuntimeException;

/**
 * Ein gescheiterter Schritt beim Abgleich des Mail-Abzeichens, samt dem, was über
 * den Grund bekannt ist.
 *
 * Bleibt innerhalb von MailBadgeService: Dort wird sie gefangen und zu genau einem
 * Protokolleintrag. `reason` nennt den Schritt (`connect_failed`, `login_failed` ...),
 * `context` das, was ihn erklärt - die Antwort des Servers, die Meldung des
 * Betriebssystems, eine Zeitüberschreitung. Ohne beides stand im Protokoll nur
 * "fehlgeschlagen", und niemand konnte sagen, woran.
 */
final class MailBadgeRefreshException extends RuntimeException
{
    /**
     * @param array<string, mixed> $context
     */
    public function __construct(
        public readonly string $reason,
        public readonly array $context = []
    ) {
        parent::__construct('Mail badge refresh failed: ' . $reason);
    }
}
