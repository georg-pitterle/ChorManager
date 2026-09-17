<?php

declare(strict_types=1);

namespace App\Controllers\Concerns;

use Psr\Http\Message\ResponseInterface as Response;

/**
 * Der gemeinsame Teil der beiden Ingest-Endpunkte.
 *
 * MailDeliveryWebhookController und MailDeliveryDsnController trugen die
 * Umformung der Provider-Rückmeldung und die JSON-Antwort wortgleich doppelt.
 * Auseinandergelaufen war die Umformung bereits: Die Webhook-Fassung nahm
 * `$sourceChannel` entgegen und schrieb trotzdem "webhook" wörtlich in den
 * Idempotenzschlüssel. Aufgefallen ist das nie, weil beide Aufrufstellen genau
 * ihren eigenen Kanal übergeben und die Zeichenkette dadurch zufällig stimmte.
 *
 * Was hier steht, teilen sich beide Controller; was sie trennt - die Prüfung
 * des Absenders, das Lesen des Rumpfes - bleibt bei ihnen. Insbesondere prüft
 * jeder weiterhin selbst, ob die Anfrage wirklich von seinem Provider kommt:
 * Das ist die Entscheidung, auf der die CSRF-Ausnahme in CsrfMiddleware ruht,
 * und sie gehört an die Stelle, die den Kanal kennt.
 *
 * Die Felder kommen von außen und sind nichts als Text: Ein Provider, der
 * `occurred_at` weglässt oder Unsinn schickt, darf den Eingang nicht
 * abbrechen. Was fehlt, wird ersetzt - über die Gültigkeit entscheidet der
 * MailEventMapperService.
 */
trait MailDeliveryIngest
{
    /**
     * Die beiden Kanäle, über die eine Rückmeldung hereinkommt. Sie stehen im
     * Trait, damit beide Controller dieselbe Zeichenkette verwenden - erreichbar
     * sind sie aber nur über eine einbindende Klasse
     * (`MailDeliveryWebhookController::CHANNEL_WEBHOOK`, im Controller selbst
     * `self::CHANNEL_WEBHOOK`). `MailDeliveryIngest::CHANNEL_WEBHOOK` wirft
     * "Cannot access trait constant ... directly"; das ist eine Eigenheit von
     * Trait-Konstanten und keine Nachlässigkeit hier.
     */
    public const CHANNEL_WEBHOOK = 'webhook';
    public const CHANNEL_DSN = 'dsn';

    /**
     * @param array<array-key, mixed> $payload
     * @return array<string, mixed>
     */
    private function normalizePayload(string $provider, string $sourceChannel, array $payload, string $rawBody): array
    {
        $normalizedType = strtolower($this->ingestText(
            $payload['event_type_normalized'] ?? $payload['event_type'] ?? 'unknown'
        ));
        $rawType = $this->ingestText($payload['event_type_raw'] ?? $payload['event_type'] ?? $normalizedType);

        $occurredAt = $this->ingestText($payload['occurred_at'] ?? null);
        if ($occurredAt === '') {
            $occurredAt = date('Y-m-d H:i:s');
        }

        $idempotencyKey = $this->ingestText($payload['idempotency_key'] ?? null);
        if ($idempotencyKey === '') {
            // Ohne eigenen Schlüssel des Providers dient der Rumpf als Kennung.
            // Kanal und Provider stehen mit davor, damit dieselbe Nutzlast über
            // zwei Wege nicht als ein Ereignis zusammenfällt.
            $idempotencyKey = hash('sha256', $provider . '|' . $sourceChannel . '|' . $rawBody);
        }

        $providerMessageId = $this->ingestText($payload['provider_message_id'] ?? null);

        return [
            'mail_queue_id' => (int) ($payload['mail_queue_id'] ?? 0),
            'provider_name' => $provider,
            'provider_message_id' => $providerMessageId !== '' ? $providerMessageId : null,
            'source_channel' => $sourceChannel,
            'event_type_normalized' => $normalizedType,
            'event_type_raw' => $rawType,
            'idempotency_key' => $idempotencyKey,
            'occurred_at' => $occurredAt,
            'received_at' => date('Y-m-d H:i:s'),
            'raw_payload' => $rawBody,
        ];
    }

    /**
     * Ein verschachteltes Feld ("event_type": {"x": 1}) ergab mit `(string)`
     * bisher die Zeichenkette "Array" samt PHP-Warnung. Leer ist die ehrlichere
     * Antwort: Der Provider hat nichts Brauchbares geschickt.
     *
     * Der Name trägt das Präfix `ingest`, weil ein Trait seine Methoden in die
     * Klasse legt und ein schlichtes `text()` dort mit einer künftigen eigenen
     * Methode desselben Namens kollidieren würde - ohne Fehler, aber mit
     * stillschweigendem Vorrang für die der Klasse.
     */
    private function ingestText(mixed $value): string
    {
        return is_scalar($value) ? trim((string) $value) : '';
    }

    /**
     * @param array<string, mixed> $payload
     */
    private function json(Response $response, array $payload, int $statusCode): Response
    {
        $encoded = json_encode($payload, JSON_UNESCAPED_UNICODE);
        $response->getBody()->write($encoded === false ? '{}' : $encoded);

        return $response->withHeader('Content-Type', 'application/json')->withStatus($statusCode);
    }
}
