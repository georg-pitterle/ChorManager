<?php

declare(strict_types=1);

namespace App\Controllers\Oidc;

use App\Queries\UserQuery;
use App\Services\Oidc\AccessTokenService;
use App\Services\Oidc\OidcClaimsBuilder;
use App\Services\Oidc\OidcClientService;
use App\Services\RememberLoginService;
use App\Services\SessionAuthService;
use App\Util\InputValidator;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;

/**
 * Die Ansprüche zum Zugriffstoken und das Abmelden.
 *
 * `/oidc/userinfo` liefert genau das, was auch im Anmeldezeugnis steht - der
 * Client darf beides vergleichen. Ein abgelaufenes oder widerrufenes Token
 * bekommt 401 und keinen Hinweis darauf, welcher der beiden Fälle vorlag.
 *
 * `/oidc/logout` beendet die ChorManager-Sitzung und leitet auf die beim
 * Client hinterlegte Adresse zurück. Eine bereits laufende Sitzung in der
 * anderen Anwendung endet dadurch nicht sofort - das ist eine bewusste
 * Festlegung der ersten Ausbaustufe: Ein gesperrtes Mitglied kommt nicht mehr
 * hinein, eine offene Sitzung läuft aber noch aus.
 */
class UserinfoController
{
    public function __construct(
        private readonly AccessTokenService $accessTokenService,
        private readonly OidcClaimsBuilder $claimsBuilder,
        private readonly OidcClientService $clientService,
        private readonly UserQuery $userQuery,
        private readonly SessionAuthService $sessionAuthService,
        private readonly RememberLoginService $rememberLoginService,
        private readonly LoggerInterface $logger = new NullLogger()
    ) {
    }

    public function claims(Request $request, Response $response): Response
    {
        $token = $this->readBearerToken($request);
        $stored = $this->accessTokenService->resolve($token);

        if ($stored === null) {
            return $this->unauthorized($response, 'Token unbekannt, abgelaufen oder widerrufen');
        }

        $user = $this->userQuery->findById((int) $stored->user_id);
        if ($user === null || !(bool) $user->is_active) {
            return $this->unauthorized($response, 'Mitglied ist nicht mehr aktiv');
        }

        return $this->json(
            $response,
            $this->claimsBuilder->build($user, (string) $stored->scope),
            200
        );
    }

    public function logout(Request $request, Response $response): Response
    {
        $params = $request->getQueryParams();
        $clientId = InputValidator::asString($params['client_id'] ?? null);
        $target = InputValidator::asString($params['post_logout_redirect_uri'] ?? null);
        $state = InputValidator::asString($params['state'] ?? null);

        // Dasselbe Aufräumen wie in AuthController::logout(). Ohne das Entwerten
        // des Remember-Me-Cookies hätte die nächste Anfrage die Sitzung sofort
        // wiederhergestellt - abgemeldet wäre dann niemand.
        $rememberCookie = $_COOKIE[RememberLoginService::COOKIE_NAME] ?? '';
        if (is_string($rememberCookie) && $rememberCookie !== '') {
            $this->rememberLoginService->invalidateByCookieValue($rememberCookie);
        }

        $this->rememberLoginService->clearRememberCookie();
        $this->sessionAuthService->clearSession();

        $client = $this->clientService->findActiveClient($clientId);
        $allowed = $client !== null
            && $target !== ''
            && $this->clientService->isPostLogoutRedirectAllowed($client, $target);

        // Ohne eingetragene Adresse geht es auf die eigene Anmeldeseite. Alles
        // andere wäre eine offene Weiterleitung auf ein frei wählbares Ziel.
        $location = '/login';
        if ($allowed) {
            $separator = str_contains($target, '?') ? '&' : '?';
            $location = $state === '' ? $target : $target . $separator . http_build_query(['state' => $state]);
        }

        $this->logger->info('OIDC logout handled.', [
            'event' => 'oidc.logout',
            'client_id' => $clientId,
            'redirected_to_client' => $allowed,
        ]);

        return $response->withHeader('Location', $location)->withStatus(302);
    }

    private function readBearerToken(Request $request): string
    {
        $header = $request->getHeaderLine('Authorization');
        if (stripos($header, 'Bearer ') === 0) {
            return trim(substr($header, 7));
        }

        return '';
    }

    private function unauthorized(Response $response, string $reason): Response
    {
        $this->logger->warning('OIDC userinfo request rejected.', [
            'event' => 'oidc.userinfo.rejected',
            'reason' => $reason,
        ]);

        return $this->json($response, ['error' => 'invalid_token'], 401)
            ->withHeader('WWW-Authenticate', 'Bearer error="invalid_token"');
    }

    /**
     * @param array<string, mixed> $payload
     */
    private function json(Response $response, array $payload, int $status): Response
    {
        $response->getBody()->write(
            (string) json_encode($payload, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE)
        );

        return $response
            ->withHeader('Content-Type', 'application/json')
            ->withHeader('Cache-Control', 'no-store')
            ->withStatus($status);
    }
}
