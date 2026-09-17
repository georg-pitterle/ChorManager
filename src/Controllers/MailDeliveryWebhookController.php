<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Services\MailDeliveryIngestPayloadNormalizer;
use App\Services\MailEventMapperService;
use App\Services\ProviderWebhookVerifier;
use App\Util\InputValidator;
use App\Util\JsonResponse;
use InvalidArgumentException;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;
use Throwable;

final class MailDeliveryWebhookController
{
    private ProviderWebhookVerifier $verifier;
    private MailEventMapperService $mapper;
    private MailDeliveryIngestPayloadNormalizer $normalizer;

    public function __construct(
        ProviderWebhookVerifier $verifier,
        MailEventMapperService $mapper,
        ?MailDeliveryIngestPayloadNormalizer $normalizer = null
    ) {
        $this->verifier = $verifier;
        $this->mapper = $mapper;
        $this->normalizer = $normalizer ?? new MailDeliveryIngestPayloadNormalizer();
    }

    public function ingest(Request $request, Response $response): Response
    {
        $provider = trim(InputValidator::asString($request->getQueryParams()['provider'] ?? null));
        if ($provider === '') {
            $provider = 'smtp2go';
        }
        $rawBody = (string) $request->getBody();

        if (!$this->verifier->verify($provider, $request->getHeaders(), $rawBody)) {
            return JsonResponse::write($response, [
                'status' => 'error',
                'message' => 'Invalid signature.',
            ], 401);
        }

        $decodedPayload = json_decode($rawBody, true);
        if (!is_array($decodedPayload)) {
            return JsonResponse::write($response, [
                'status' => 'error',
                'message' => 'Invalid JSON payload.',
            ], 400);
        }

        try {
            $this->mapper->mapEvent($this->normalizer->normalize(
                $provider,
                MailDeliveryIngestPayloadNormalizer::CHANNEL_WEBHOOK,
                $decodedPayload,
                $rawBody
            ));
        } catch (InvalidArgumentException $exception) {
            return JsonResponse::write($response, [
                'status' => 'error',
                'message' => $exception->getMessage(),
            ], 400);
        } catch (Throwable) {
            return JsonResponse::write($response, [
                'status' => 'error',
                'message' => 'Webhook ingest failed.',
            ], 500);
        }

        return JsonResponse::write($response, ['status' => 'ok'], 200);
    }
}
