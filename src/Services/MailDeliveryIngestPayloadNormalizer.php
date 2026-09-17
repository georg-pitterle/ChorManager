<?php

declare(strict_types=1);

namespace App\Services;

/**
 * Bringt die Rückmeldung eines Mailproviders in die Form, die
 * MailEventMapperService erwartet.
 *
 * Anlass: MailDeliveryWebhookController und MailDeliveryDsnController trugen
 * dieselbe Umformung wortgleich doppelt. Auseinandergelaufen war sie bereits -
 * die Webhook-Fassung nahm `$sourceChannel` entgegen und schrieb trotzdem
 * "webhook" wörtlich in den Idempotenzschlüssel. Aufgefallen ist das nie, weil
 * beide Aufrufstellen genau ihren eigenen Kanal übergeben und die Zeichenkette
 * dadurch zufällig stimmte.
 *
 * Bewusst ein eigener Dienst und kein Trait: In src/ gibt es bislang kein
 * einziges Trait, und ein Wert-zu-Wert-Umbau braucht keinen Zugriff auf den
 * Controller. So lässt er sich zudem für sich prüfen, ohne eine Anfrage zu
 * bauen.
 *
 * Die Felder kommen von außen und sind nichts als Text: Ein Provider, der
 * `occurred_at` weglässt oder Unsinn schickt, darf den Eingang nicht abbrechen.
 * Was hier fehlt, wird ersetzt - über die Gültigkeit entscheidet der Mapper.
 */
final class MailDeliveryIngestPayloadNormalizer
{
    public const CHANNEL_WEBHOOK = 'webhook';
    public const CHANNEL_DSN = 'dsn';

    /**
     * @param array<array-key, mixed> $payload
     * @return array<string, mixed>
     */
    public function normalize(string $provider, string $sourceChannel, array $payload, string $rawBody): array
    {
        $normalizedType = strtolower($this->text(
            $payload['event_type_normalized'] ?? $payload['event_type'] ?? 'unknown'
        ));
        $rawType = $this->text($payload['event_type_raw'] ?? $payload['event_type'] ?? $normalizedType);

        $occurredAt = $this->text($payload['occurred_at'] ?? null);
        if ($occurredAt === '') {
            $occurredAt = date('Y-m-d H:i:s');
        }

        $idempotencyKey = $this->text($payload['idempotency_key'] ?? null);
        if ($idempotencyKey === '') {
            // Ohne eigenen Schlüssel des Providers dient der Rumpf als Kennung.
            // Kanal und Provider stehen mit davor, damit dieselbe Nutzlast über
            // zwei Wege nicht als ein Ereignis zusammenfällt.
            $idempotencyKey = hash('sha256', $provider . '|' . $sourceChannel . '|' . $rawBody);
        }

        $providerMessageId = $this->text($payload['provider_message_id'] ?? null);

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
     */
    private function text(mixed $value): string
    {
        return is_scalar($value) ? trim((string) $value) : '';
    }
}
