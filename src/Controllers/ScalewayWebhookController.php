<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Controllers\Concerns\MailDeliveryIngest;
use App\Models\MailQueue;
use App\Services\MailEventMapperService;
use App\Services\Scaleway\SnsMessageVerifier;
use DateTimeImmutable;
use DateTimeZone;
use Exception;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * Zustell- und Bounce-Rückmeldungen von Scaleway Transactional Email (TEM).
 *
 * TEM ruft keinen Webhook direkt auf, sondern veröffentlicht seine Ereignisse
 * in einem Topic von Scaleway Topics and Events; dieser Endpunkt ist dort als
 * HTTPS-Abonnement eingetragen. Was ankommt, ist eine SNS-Hülle - das
 * TEM-Ereignis steckt als JSON-Text in deren Feld `Message`.
 *
 * Eigener Endpunkt statt `/mail/delivery/webhook?provider=scaleway`: Jener
 * erwartet ein bereits umgeformtes Ereignis und eine HMAC-Signatur, Scaleway
 * schickt beides nicht.
 *
 * Zugeordnet wird über die Message-ID: PHPMailer vergibt sie beim Versand, sie
 * steht in `mail_queue.provider_message_id` und kommt in den `email_headers`
 * des Ereignisses zurück.
 */
final class ScalewayWebhookController
{
    use MailDeliveryIngest;

    public const PROVIDER = 'scaleway';

    /**
     * TEM-Ereignistyp => Typ, den MailEventMapperService versteht. Endgültig
     * sind nur delivered, bounced und complained; die übrigen werden
     * festgehalten, ändern den Zustand der Mail aber nicht.
     *
     * `email_spam` heißt, Scaleway oder der Empfangsserver haben die Mail als
     * Spam eingestuft - das kommt einer Beschwerde näher als einem Bounce.
     */
    private const EVENT_TYPE_MAP = [
        'email_delivered' => 'delivered',
        'email_dropped' => 'bounced',
        'email_mailbox_not_found' => 'bounced',
        'email_blocklisted' => 'bounced',
        'email_spam' => 'complained',
        'email_deferred' => 'deferred',
        'email_queued' => 'queued',
    ];

    public function __construct(
        private readonly SnsMessageVerifier $verifier,
        private readonly MailEventMapperService $mapper,
        private readonly LoggerInterface $logger
    ) {
    }

    public function ingest(Request $request, Response $response): Response
    {
        $rawBody = (string) $request->getBody();
        $message = json_decode($rawBody, true);
        if (!is_array($message)) {
            return $this->json($response, ['status' => 'error', 'message' => 'Invalid JSON payload.'], 400);
        }

        $rejection = $this->verifier->rejectionReason($message);
        if ($rejection !== null) {
            $this->logger->warning('Scaleway webhook rejected.', [
                'event' => 'mail.webhook.scaleway.rejected',
                'reason' => $rejection,
                'sns_type' => $this->ingestText($message['Type'] ?? null),
                'topic_arn' => $this->ingestText($message['TopicArn'] ?? null),
            ]);

            return $this->json($response, ['status' => 'error', 'message' => 'Invalid signature.'], 401);
        }

        return match ($message['Type']) {
            'Notification' => $this->ingestNotification($message, $rawBody, $response),
            'SubscriptionConfirmation', 'UnsubscribeConfirmation' => $this->logSubscription($message, $response),
            default => $this->json($response, ['status' => 'error', 'message' => 'Unknown message type.'], 400),
        };
    }

    /**
     * Scaleway verlangt, dass die SubscribeURL in der Konsole eingetragen wird,
     * bevor das Abonnement Nachrichten bekommt. Bestätigt wird bewusst nicht
     * von hier aus: Das hieße, eine Adresse aus einer eingehenden Nachricht
     * aufzurufen, und es geschieht ohnehin nur einmal.
     *
     * @param array<string, mixed> $message
     */
    private function logSubscription(array $message, Response $response): Response
    {
        $this->logger->info('Scaleway topic subscription message received.', [
            'event' => $message['Type'] === 'SubscriptionConfirmation'
                ? 'mail.webhook.scaleway.subscription_confirmation'
                : 'mail.webhook.scaleway.unsubscribe_confirmation',
            'topic_arn' => $message['TopicArn'],
            'subscribe_url' => $this->ingestText($message['SubscribeURL'] ?? null),
        ]);

        return $this->json($response, ['status' => 'ok'], 200);
    }

    /**
     * @param array<string, mixed> $message
     */
    private function ingestNotification(array $message, string $rawBody, Response $response): Response
    {
        $temEvent = json_decode((string) $message['Message'], true);
        if (!is_array($temEvent)) {
            return $this->json($response, ['status' => 'error', 'message' => 'Invalid event payload.'], 400);
        }

        $rawType = $this->ingestText($temEvent['type'] ?? null);
        $eventId = $this->ingestText($temEvent['id'] ?? null);
        $messageId = $this->messageIdFromHeaders($temEvent['email_headers'] ?? null);
        $queue = $messageId !== null ? $this->findQueueEntry($messageId) : null;

        // Ohne zugehörige Mail lässt sich das Ereignis nicht speichern
        // (mail_delivery_events.mail_queue_id ist Pflicht). Quittiert wird
        // trotzdem: Ein Fehlercode brächte Scaleway nur dazu, die Nachricht
        // wieder und wieder zu schicken, und an der Lage änderte das nichts.
        if ($queue === null) {
            $this->logger->info('Scaleway event without matching mail.', [
                'event' => 'mail.webhook.scaleway.unmatched',
                'tem_event_type' => $rawType,
                'tem_event_id' => $eventId,
                'tem_email_id' => $this->ingestText($temEvent['email_id'] ?? null),
                'provider_message_id' => $messageId,
            ]);

            return $this->json($response, ['status' => 'ok', 'matched' => false], 200);
        }

        $normalized = $this->normalizePayload(self::PROVIDER, self::CHANNEL_WEBHOOK, [
            'mail_queue_id' => (int) $queue->id,
            'provider_message_id' => $messageId,
            'event_type_normalized' => self::EVENT_TYPE_MAP[$rawType] ?? 'unknown',
            'event_type_raw' => $rawType !== '' ? $rawType : 'unknown',
            'occurred_at' => $this->localTime($temEvent['created_at'] ?? null),
            'idempotency_key' => 'scaleway:' . ($eventId !== '' ? $eventId : $message['MessageId']),
        ], $rawBody);

        try {
            $this->mapper->mapEvent($normalized);
        } catch (Throwable $exception) {
            $this->logger->error('Scaleway event could not be stored.', [
                'event' => 'mail.webhook.scaleway.failed',
                'tem_event_id' => $eventId,
                'mail_queue_id' => (int) $queue->id,
                'exception' => $exception,
            ]);

            return $this->json($response, ['status' => 'error', 'message' => 'Webhook ingest failed.'], 500);
        }

        return $this->json($response, ['status' => 'ok', 'matched' => true], 200);
    }

    /**
     * Die Doku nennt die Felder eines Headers `key`/`value`, die API-Referenz
     * `name`/`value`. Gelesen wird beides; der Header-Name ist wie in jeder
     * Mail unabhängig von Groß- und Kleinschreibung.
     */
    private function messageIdFromHeaders(mixed $headers): ?string
    {
        if (!is_array($headers)) {
            return null;
        }

        foreach ($headers as $header) {
            if (!is_array($header)) {
                continue;
            }
            $name = strtolower($this->ingestText($header['key'] ?? $header['name'] ?? null));
            $value = $this->ingestText($header['value'] ?? null);
            if ($name === 'message-id' && $value !== '') {
                return $value;
            }
        }

        return null;
    }

    /**
     * PHPMailer speichert die Message-ID mit spitzen Klammern; ob Scaleway sie
     * mitschickt, ist nicht dokumentiert. Gesucht wird darum in beiden Formen.
     */
    private function findQueueEntry(string $messageId): ?MailQueue
    {
        $bare = trim($messageId, '<> ');
        if ($bare === '') {
            return null;
        }

        $queue = MailQueue::query()
            ->whereIn('provider_message_id', ['<' . $bare . '>', $bare])
            ->orderByDesc('id')
            ->first();

        return $queue instanceof MailQueue ? $queue : null;
    }

    /**
     * Scaleway liefert RFC 3339 mit Zeitzone, die Spalten sind lokale Zeit
     * ohne Zone - wie überall in der App.
     */
    private function localTime(mixed $value): string
    {
        $text = $this->ingestText($value);
        if ($text !== '') {
            try {
                return (new DateTimeImmutable($text))
                    ->setTimezone(new DateTimeZone(date_default_timezone_get()))
                    ->format('Y-m-d H:i:s');
            } catch (Exception) {
                // Unlesbar: wie ein fehlender Wert behandeln.
            }
        }

        return date('Y-m-d H:i:s');
    }
}
