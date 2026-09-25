<?php

declare(strict_types=1);

namespace App\Controllers\Oidc;

use App\Models\OidcClient;
use App\Queries\UserQuery;
use App\Services\Oidc\AuthorizationCodeService;
use App\Services\Oidc\OidcClaimsBuilder;
use App\Services\Oidc\OidcClientService;
use App\Services\RateLimiterService;
use App\Util\InputValidator;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;

/**
 * Der Anmeldeschritt des Providers.
 *
 * Die Route liegt in der geschützten Gruppe. Damit übernimmt AuthMiddleware den
 * Umweg über das Anmeldeformular: Sie hängt die vollständige Adresse dieser
 * Anfrage an `?redirect=`, und AuthController schickt nach erfolgreicher
 * Anmeldung dorthin zurück - `state`, `nonce` und `code_challenge` überstehen
 * das unverändert, weil SafeRedirect den Query-Teil nicht antastet.
 *
 * Zwei Arten von Fehlern, sauber getrennt:
 *
 * - Stimmt der Client oder die Rücksprungadresse nicht, wird nirgendwohin
 *   umgeleitet, sondern mit 400 geantwortet. Alles andere wäre eine offene
 *   Weiterleitung auf eine vom Aufrufer bestimmte Adresse.
 * - Stimmt beides, gehen Protokollfehler als `error=`-Parameter an genau diese
 *   geprüfte Adresse zurück, wie der Standard es verlangt.
 */
class AuthorizeController
{
    /** Wie beim Anmeldeformular: 10 Versuche in 15 Minuten. */
    private const RATE_LIMIT_ATTEMPTS = 10;
    private const RATE_LIMIT_WINDOW = 900;

    public function __construct(
        private readonly OidcClientService $clientService,
        private readonly AuthorizationCodeService $codeService,
        private readonly UserQuery $userQuery,
        private readonly RateLimiterService $rateLimiter = new RateLimiterService(),
        private readonly LoggerInterface $logger = new NullLogger()
    ) {
    }

    public function authorize(Request $request, Response $response): Response
    {
        $params = $request->getQueryParams();
        $clientId = InputValidator::asString($params['client_id'] ?? null);
        $redirectUri = InputValidator::asString($params['redirect_uri'] ?? null);

        $client = $this->clientService->findActiveClient($clientId);
        if (!$this->clientService->isUsableForLogin($client)) {
            return $this->refuse($response, 'unbekannter oder nicht vertrauenswürdiger Client', $clientId);
        }

        /** @var OidcClient $client */
        if (!$this->clientService->isRedirectUriAllowed($client, $redirectUri)) {
            return $this->refuse($response, 'redirect_uri ist für diesen Client nicht eingetragen', $clientId);
        }

        $state = InputValidator::asString($params['state'] ?? null);
        $responseType = InputValidator::asString($params['response_type'] ?? null);
        $challenge = InputValidator::asString($params['code_challenge'] ?? null);
        $challengeMethod = InputValidator::asString($params['code_challenge_method'] ?? null);
        $nonce = InputValidator::asString($params['nonce'] ?? null);
        $nonce = $nonce === '' ? null : $nonce;
        $scope = InputValidator::asString($params['scope'] ?? null) ?: 'openid';

        if ($responseType !== 'code') {
            return $this->fail($response, $redirectUri, $state, 'unsupported_response_type', 'nur code wird angeboten');
        }

        if ($challenge === '') {
            return $this->fail($response, $redirectUri, $state, 'invalid_request', 'code_challenge fehlt');
        }

        // Ohne S256 stünde der Verifier im Klartext in dieser Anfrage und PKCE
        // wäre wirkungslos. `plain` steht deshalb auch nicht im Discovery-Dokument.
        if ($challengeMethod !== AuthorizationCodeService::CHALLENGE_METHOD) {
            return $this->fail($response, $redirectUri, $state, 'invalid_request', 'nur S256 wird angeboten');
        }

        if (!in_array('openid', OidcClaimsBuilder::splitScope($scope), true)) {
            return $this->fail($response, $redirectUri, $state, 'invalid_scope', 'openid fehlt im scope');
        }

        $userId = (int) ($_SESSION['user_id'] ?? 0);
        $user = $userId > 0 ? $this->userQuery->findById($userId) : null;

        // AuthMiddleware hat die Sitzung bereits geprüft. Hier wird der Zustand
        // trotzdem noch einmal frisch gelesen: Diese Anmeldung wirkt in einer
        // fremden Anwendung weiter, und ein gerade gesperrtes Mitglied darf sie
        // nicht mehr bekommen.
        if ($user === null || !(bool) $user->is_active) {
            return $this->fail($response, $redirectUri, $state, 'access_denied', 'Mitglied ist nicht aktiv');
        }

        $limit = $this->rateLimiter->hit(
            'oidc:authorize:' . $userId,
            self::RATE_LIMIT_ATTEMPTS,
            self::RATE_LIMIT_WINDOW
        );

        if (!$limit['allowed']) {
            return $this->fail($response, $redirectUri, $state, 'temporarily_unavailable', 'Bremse hat gegriffen');
        }

        $this->codeService->clearExpired();

        $code = $this->codeService->issue(
            $client,
            $userId,
            $redirectUri,
            $scope,
            $nonce,
            $challenge
        );

        return $this->redirect($response, $redirectUri, ['code' => $code, 'state' => $state]);
    }

    /**
     * Die Anfrage taugt nicht einmal für eine Rückleitung - der Fehler bleibt
     * hier stehen.
     */
    private function refuse(Response $response, string $reason, string $clientId): Response
    {
        $this->logger->warning('OIDC authorize request refused.', [
            'event' => 'oidc.authorize.denied',
            'reason' => $reason,
            'client_id' => $clientId,
        ]);

        $response->getBody()->write('Diese Anmeldeanfrage ist ungültig.');

        return $response->withHeader('Content-Type', 'text/plain; charset=utf-8')->withStatus(400);
    }

    private function fail(
        Response $response,
        string $redirectUri,
        string $state,
        string $error,
        string $reason
    ): Response {
        $this->logger->warning('OIDC authorize request rejected.', [
            'event' => 'oidc.authorize.denied',
            'error' => $error,
            'reason' => $reason,
        ]);

        return $this->redirect($response, $redirectUri, ['error' => $error, 'state' => $state]);
    }

    /**
     * @param array<string, string> $params
     */
    private function redirect(Response $response, string $redirectUri, array $params): Response
    {
        $query = [];
        foreach ($params as $name => $value) {
            if ($value !== '') {
                $query[$name] = $value;
            }
        }

        $separator = str_contains($redirectUri, '?') ? '&' : '?';

        return $response
            ->withHeader('Location', $redirectUri . $separator . http_build_query($query))
            ->withStatus(302);
    }
}
