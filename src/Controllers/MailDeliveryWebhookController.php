<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Controllers\Concerns\MailDeliveryIngest;
use App\Services\MailEventMapperService;
use App\Services\ProviderWebhookVerifier;
use App\Util\InputValidator;
use InvalidArgumentException;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;
use Throwable;

final class MailDeliveryWebhookController
{
    use MailDeliveryIngest;

    private ProviderWebhookVerifier $verifier;
    private MailEventMapperService $mapper;

    public function __construct(ProviderWebhookVerifier $verifier, MailEventMapperService $mapper)
    {
        $this->verifier = $verifier;
        $this->mapper = $mapper;
    }

    public function ingest(Request $request, Response $response): Response
    {
        $provider = trim(InputValidator::asString($request->getQueryParams()['provider'] ?? null));
        if ($provider === '') {
            $provider = 'smtp2go';
        }
        $rawBody = (string) $request->getBody();

        if (!$this->verifier->verify($provider, $request->getHeaders(), $rawBody)) {
            return $this->json($response, [
                'status' => 'error',
                'message' => 'Invalid signature.',
            ], 401);
        }

        $decodedPayload = json_decode($rawBody, true);
        if (!is_array($decodedPayload)) {
            return $this->json($response, [
                'status' => 'error',
                'message' => 'Invalid JSON payload.',
            ], 400);
        }

        try {
            $this->mapper->mapEvent($this->normalizePayload(
                $provider,
                self::CHANNEL_WEBHOOK,
                $decodedPayload,
                $rawBody
            ));
        } catch (InvalidArgumentException $exception) {
            return $this->json($response, [
                'status' => 'error',
                'message' => $exception->getMessage(),
            ], 400);
        } catch (Throwable) {
            return $this->json($response, [
                'status' => 'error',
                'message' => 'Webhook ingest failed.',
            ], 500);
        }

        return $this->json($response, ['status' => 'ok'], 200);
    }
}
