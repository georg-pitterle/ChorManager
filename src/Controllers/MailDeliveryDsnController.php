<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Services\MailEventMapperService;
use App\Services\ProviderWebhookVerifier;
use InvalidArgumentException;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;
use Throwable;

final class MailDeliveryDsnController
{
    private ProviderWebhookVerifier $verifier;
    private MailEventMapperService $mapper;

    public function __construct(ProviderWebhookVerifier $verifier, MailEventMapperService $mapper)
    {
        $this->verifier = $verifier;
        $this->mapper = $mapper;
    }

    public function ingest(Request $request, Response $response): Response
    {
        if (!$this->verifier->verifyDsn($request->getHeaders())) {
            return $this->json($response, [
                'status' => 'error',
                'message' => 'Unauthorized DSN request.',
            ], 401);
        }

        $parsedBody = $request->getParsedBody();

        if (!is_array($parsedBody)) {
            return $this->json($response, [
                'status' => 'error',
                'message' => 'Invalid DSN payload.',
            ], 400);
        }

        $rawBody = json_encode($parsedBody, JSON_UNESCAPED_UNICODE);
        if ($rawBody === false) {
            $rawBody = '{}';
        }

        $provider = trim((string) ($parsedBody['provider_name'] ?? 'smtp2go'));
        if ($provider === '') {
            $provider = 'smtp2go';
        }

        try {
            $this->mapper->mapEvent($this->normalizePayload($provider, 'dsn', $parsedBody, $rawBody));
        } catch (InvalidArgumentException $exception) {
            return $this->json($response, [
                'status' => 'error',
                'message' => $exception->getMessage(),
            ], 400);
        } catch (Throwable) {
            return $this->json($response, [
                'status' => 'error',
                'message' => 'DSN ingest failed.',
            ], 500);
        }

        return $this->json($response, ['status' => 'ok'], 200);
    }

    /**
     * @return array<string, mixed>
     */
    private function normalizePayload(string $provider, string $sourceChannel, array $payload, string $rawBody): array
    {
        $normalizedType = strtolower(trim((string) ($payload['event_type_normalized'] ?? $payload['event_type'] ?? 'unknown')));
        $rawType = trim((string) ($payload['event_type_raw'] ?? $payload['event_type'] ?? $normalizedType));

        $occurredAt = trim((string) ($payload['occurred_at'] ?? ''));
        if ($occurredAt === '') {
            $occurredAt = date('Y-m-d H:i:s');
        }

        $idempotencyKey = trim((string) ($payload['idempotency_key'] ?? ''));
        if ($idempotencyKey === '') {
            $idempotencyKey = hash('sha256', $provider . '|dsn|' . $rawBody);
        }

        $providerMessageId = trim((string) ($payload['provider_message_id'] ?? ''));

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
     * @param array<string, mixed> $payload
     */
    private function json(Response $response, array $payload, int $statusCode): Response
    {
        $encoded = json_encode($payload, JSON_UNESCAPED_UNICODE);
        $response->getBody()->write($encoded === false ? '{}' : $encoded);

        return $response->withHeader('Content-Type', 'application/json')->withStatus($statusCode);
    }
}
