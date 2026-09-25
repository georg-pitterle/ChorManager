<?php

declare(strict_types=1);

namespace App\Controllers\Oidc;

use App\Services\Oidc\IdTokenSigner;
use App\Services\Oidc\OidcEndpoints;
use App\Util\AppUrlResolver;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;

/**
 * Das Discovery-Dokument und der öffentliche Schlüsselsatz.
 *
 * Beides ist öffentlich und enthält keine personenbezogenen Daten - im
 * Gegenteil, ein Client muss es lesen können, bevor sich überhaupt jemand
 * angemeldet hat. Beide Antworten dürfen deshalb kurz zwischengespeichert
 * werden. Ohne eigenes `Cache-Control` setzte SecurityHeadersMiddleware hier
 * `no-store`, und `user_oidc` holte den Schlüsselsatz bei jeder Anmeldung neu.
 */
class DiscoveryController
{
    /** Kurz genug, dass eine Rotation binnen einer Stunde durchschlägt. */
    private const CACHE_SECONDS = 3600;

    public function __construct(private readonly IdTokenSigner $signer)
    {
    }

    public function configuration(Request $request, Response $response): Response
    {
        $issuer = AppUrlResolver::resolveBaseUrl($request);

        return $this->json($response, [
            'issuer' => $issuer,
            'authorization_endpoint' => $issuer . OidcEndpoints::AUTHORIZE,
            'token_endpoint' => $issuer . OidcEndpoints::TOKEN,
            'userinfo_endpoint' => $issuer . OidcEndpoints::USERINFO,
            'jwks_uri' => $issuer . OidcEndpoints::JWKS,
            'end_session_endpoint' => $issuer . OidcEndpoints::LOGOUT,
            'scopes_supported' => ['openid', 'profile', 'email', 'groups'],
            'response_types_supported' => ['code'],
            'response_modes_supported' => ['query'],
            'grant_types_supported' => ['authorization_code'],
            'subject_types_supported' => ['public'],
            'id_token_signing_alg_values_supported' => ['RS256'],
            // Ausschließlich S256. `plain` stünde als Verifier im Klartext in der
            // Authorize-Anfrage und machte PKCE damit wirkungslos.
            'code_challenge_methods_supported' => ['S256'],
            'token_endpoint_auth_methods_supported' => ['client_secret_post', 'client_secret_basic'],
            'claims_supported' => [
                'sub',
                'iss',
                'aud',
                'exp',
                'iat',
                'nonce',
                'name',
                'preferred_username',
                'email',
                'email_verified',
                'groups',
            ],
        ]);
    }

    public function jwks(Request $request, Response $response): Response
    {
        return $this->json($response, $this->signer->jwks());
    }

    /**
     * @param array<string, mixed> $payload
     */
    private function json(Response $response, array $payload): Response
    {
        $response->getBody()->write(
            (string) json_encode($payload, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE)
        );

        return $response
            ->withHeader('Content-Type', 'application/json')
            ->withHeader('Cache-Control', 'public, max-age=' . self::CACHE_SECONDS);
    }
}
