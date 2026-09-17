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

final class MailDeliveryDsnController
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

        $provider = trim(InputValidator::asString($parsedBody['provider_name'] ?? null));
        if ($provider === '') {
            $provider = 'smtp2go';
        }

        try {
            $this->mapper->mapEvent($this->normalizePayload(
                $provider,
                self::CHANNEL_DSN,
                $parsedBody,
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
                'message' => 'DSN ingest failed.',
            ], 500);
        }

        return $this->json($response, ['status' => 'ok'], 200);
    }
}
