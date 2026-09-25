<?php

declare(strict_types=1);

namespace App\Controllers\Oidc;

use App\Queries\UserQuery;
use App\Services\Oidc\AccessTokenService;
use App\Services\Oidc\AuthorizationCodeService;
use App\Services\Oidc\IdTokenSigner;
use App\Services\Oidc\OidcClaimsBuilder;
use App\Services\Oidc\OidcClientService;
use App\Services\RateLimiterService;
use App\Services\Oidc\OidcProtocolException;
use App\Util\AppUrlResolver;
use App\Util\InputValidator;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;

/**
 * Tauscht den Autorisierungscode gegen Anmeldezeugnis und Zugriffstoken.
 *
 * Der Aufrufer ist kein Browser und hat keine Sitzung - ausgewiesen wird sich
 * mit Client-Kennung, Client-Secret und `code_verifier`. Genau deshalb steht
 * der Pfad in der CSRF-Ausnahme, und genau deshalb wird hier zuerst der
 * Client geprüft, bevor irgendetwas anderes angefasst wird.
 *
 * Nach außen gehen nur die knappen Standardfehler. Woran es lag, steht im Log.
 */
class TokenController
{
    /** Der Code lebt 60 Sekunden, das Zeugnis fünf Minuten. */
    private const ID_TOKEN_TTL_SECONDS = 300;

    private const RATE_LIMIT_ATTEMPTS = 30;
    private const RATE_LIMIT_WINDOW = 900;

    public function __construct(
        private readonly OidcClientService $clientService,
        private readonly AuthorizationCodeService $codeService,
        private readonly AccessTokenService $accessTokenService,
        private readonly IdTokenSigner $signer,
        private readonly OidcClaimsBuilder $claimsBuilder,
        private readonly UserQuery $userQuery,
        private readonly RateLimiterService $rateLimiter = new RateLimiterService(),
        private readonly LoggerInterface $logger = new NullLogger()
    ) {
    }

    public function issue(Request $request, Response $response): Response
    {
        $body = $request->getParsedBody();
        $body = is_array($body) ? $body : [];

        [$clientId, $clientSecret] = $this->readClientCredentials($request, $body);

        try {
            $client = $this->clientService->authenticate($clientId, $clientSecret);
            if (!$this->clientService->isUsableForLogin($client)) {
                throw new OidcProtocolException('invalid_client', 'Client unbekannt, gesperrt oder ohne Vertrauen', 401);
            }

            $limit = $this->rateLimiter->hit(
                'oidc:token:' . $clientId,
                self::RATE_LIMIT_ATTEMPTS,
                self::RATE_LIMIT_WINDOW
            );
            if (!$limit['allowed']) {
                throw new OidcProtocolException('invalid_request', 'Bremse hat gegriffen', 429);
            }

            if (InputValidator::asString($body['grant_type'] ?? null) !== 'authorization_code') {
                throw new OidcProtocolException('unsupported_grant_type', 'nur authorization_code wird angeboten');
            }

            $code = $this->codeService->redeem(
                InputValidator::asString($body['code'] ?? null),
                $clientId,
                InputValidator::asString($body['redirect_uri'] ?? null),
                InputValidator::asString($body['code_verifier'] ?? null)
            );

            $user = $this->userQuery->findById((int) $code->user_id);
            if ($user === null || !(bool) $user->is_active) {
                throw new OidcProtocolException('invalid_grant', 'Mitglied ist nicht mehr aktiv');
            }

            $accessToken = $this->accessTokenService->issueForCode($code);
            $issuer = AppUrlResolver::resolveBaseUrl($request);
            $issuedAt = time();

            $claims = $this->claimsBuilder->build($user, (string) $code->scope);
            $claims['iss'] = $issuer;
            $claims['aud'] = $clientId;
            $claims['iat'] = $issuedAt;
            $claims['exp'] = $issuedAt + self::ID_TOKEN_TTL_SECONDS;
            $claims['auth_time'] = $issuedAt;
            if (($code->nonce ?? null) !== null) {
                $claims['nonce'] = (string) $code->nonce;
            }

            $idToken = $this->signer->sign($claims);

            // Billiges, gelegentliches Aufräumen statt eines eigenen Cronjobs -
            // dasselbe Muster wie RememberLoginService::clearExpiredTokens().
            $this->codeService->clearExpired();

            $this->logger->info('OIDC tokens issued.', [
                'event' => 'oidc.token.issued',
                'client_id' => $clientId,
                'user_id' => (int) $user->id,
            ]);

            return $this->json($response, [
                'access_token' => $accessToken,
                'token_type' => 'Bearer',
                'expires_in' => AccessTokenService::TOKEN_TTL_SECONDS,
                'id_token' => $idToken,
                'scope' => (string) $code->scope,
            ], 200);
        } catch (OidcProtocolException $exception) {
            $this->logger->warning('OIDC token request rejected.', [
                'event' => 'oidc.token.rejected',
                'error' => $exception->errorCode(),
                'reason' => $exception->reason(),
                'client_id' => $clientId,
            ]);

            return $this->json($response, ['error' => $exception->errorCode()], $exception->status());
        }
    }

    /**
     * Client-Kennung und Secret, aus dem Formular oder aus HTTP-Basic - beides
     * nennt das Discovery-Dokument, und `user_oidc` nutzt je nach Einstellung
     * das eine oder das andere.
     *
     * @param array<mixed> $body
     * @return array{0:string, 1:string}
     */
    private function readClientCredentials(Request $request, array $body): array
    {
        $header = $request->getHeaderLine('Authorization');
        if (stripos($header, 'Basic ') === 0) {
            $decoded = base64_decode(substr($header, 6), true);
            if (is_string($decoded) && str_contains($decoded, ':')) {
                [$user, $password] = explode(':', $decoded, 2);

                return [urldecode($user), urldecode($password)];
            }
        }

        return [
            InputValidator::asString($body['client_id'] ?? null),
            InputValidator::asString($body['client_secret'] ?? null),
        ];
    }

    /**
     * @param array<string, mixed> $payload
     */
    private function json(Response $response, array $payload, int $status): Response
    {
        $response->getBody()->write(
            (string) json_encode($payload, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES)
        );

        // Der Standard verlangt es, und SecurityHeadersMiddleware setzt es
        // ohnehin - hier steht es, damit es auch ohne sie stimmt.
        return $response
            ->withHeader('Content-Type', 'application/json')
            ->withHeader('Cache-Control', 'no-store')
            ->withHeader('Pragma', 'no-cache')
            ->withStatus($status);
    }
}
