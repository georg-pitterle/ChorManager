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

final class MailDeliveryDsnController
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
        if (!$this->verifier->verifyDsn($request->getHeaders())) {
            return JsonResponse::write($response, [
                'status' => 'error',
                'message' => 'Unauthorized DSN request.',
            ], 401);
        }

        $parsedBody = $request->getParsedBody();

        if (!is_array($parsedBody)) {
            return JsonResponse::write($response, [
                'status' => 'error',
                'message' => 'Invalid DSN payload.',
            ], 400);
        }

        $rawBody = json_encode($parsedBody, JSON_UNESCAPED_UNICODE);
        if ($rawBody === false) {
            $rawBody = '{}';
        }

        $provider = trim(InputValidator::asString($parsedBody['provider_name'] ?? null));
        if ($provider === '') {
            $provider = 'smtp2go';
        }

        try {
            $this->mapper->mapEvent($this->normalizer->normalize(
                $provider,
                MailDeliveryIngestPayloadNormalizer::CHANNEL_DSN,
                $parsedBody,
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
                'message' => 'DSN ingest failed.',
            ], 500);
        }

        return JsonResponse::write($response, ['status' => 'ok'], 200);
    }
}
