<?php

declare(strict_types=1);

namespace App\Services\Oidc;

use RuntimeException;

/**
 * Baut und signiert die Anmeldezeugnisse (RS256) und liefert den zugehörigen
 * öffentlichen Schlüsselsatz für /oidc/jwks.json.
 *
 * ChorManager signiert nur - es prüft nie ein fremdes Zeugnis. Die gefährliche
 * JWT-Klasse (`alg: none`, RS/HS-Verwechslung, Key-Confusion) entsteht
 * ausschließlich beim Prüfen und betrifft diese Klasse deshalb nicht. Dieselbe
 * Asymmetrie und dieselbe Begründung trägt WebmailSsoTokenService.
 *
 * Eigenbau von Kryptografie findet hier nicht statt: Signiert wird mit
 * openssl_sign(), kodiert wird base64url, und der Schlüsselsatz kommt
 * unverändert aus openssl_pkey_get_details().
 */
class IdTokenSigner
{
    public function __construct(private readonly OidcSigningKeyService $keyService)
    {
    }

    /**
     * @param array<string, mixed> $claims
     */
    public function sign(array $claims): string
    {
        $key = $this->keyService->activeKey();
        if ($key === null) {
            throw new RuntimeException('Es ist kein aktiver OIDC-Signierschlüssel hinterlegt.');
        }

        $privateKey = openssl_pkey_get_private($this->keyService->activePrivateKey());
        if ($privateKey === false) {
            throw new RuntimeException('Der hinterlegte private Signierschlüssel ist unbrauchbar.');
        }

        $header = self::encodeSegment([
            'alg' => 'RS256',
            'typ' => 'JWT',
            'kid' => (string) $key->kid,
        ]);
        $payload = self::encodeSegment($claims);

        $signature = '';
        if (openssl_sign($header . '.' . $payload, $signature, $privateKey, OPENSSL_ALGO_SHA256) === false) {
            throw new RuntimeException('Das Anmeldezeugnis ließ sich nicht signieren.');
        }

        return $header . '.' . $payload . '.' . self::base64UrlEncode($signature);
    }

    /**
     * Der öffentliche Schlüsselsatz, wie ihn /oidc/jwks.json ausliefert.
     *
     * Enthält auch abgelöste Schlüssel: Ein Client, der seinen Satz
     * zwischenspeichert, muss ein kurz vor der Rotation ausgestelltes Zeugnis
     * noch prüfen können.
     *
     * @return array{keys: list<array<string, string>>}
     */
    public function jwks(): array
    {
        $keys = [];

        foreach ($this->keyService->allKeys() as $key) {
            $publicKey = openssl_pkey_get_public((string) $key->public_key);
            if ($publicKey === false) {
                continue;
            }

            $details = openssl_pkey_get_details($publicKey);
            if ($details === false || !isset($details['rsa']['n'], $details['rsa']['e'])) {
                continue;
            }

            $keys[] = [
                'kty' => 'RSA',
                'use' => 'sig',
                'alg' => 'RS256',
                'kid' => (string) $key->kid,
                'n' => self::base64UrlEncode((string) $details['rsa']['n']),
                'e' => self::base64UrlEncode((string) $details['rsa']['e']),
            ];
        }

        return ['keys' => $keys];
    }

    /**
     * @param array<string, mixed> $data
     */
    private static function encodeSegment(array $data): string
    {
        return self::base64UrlEncode(
            json_encode($data, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE)
        );
    }

    private static function base64UrlEncode(string $raw): string
    {
        return rtrim(strtr(base64_encode($raw), '+/', '-_'), '=');
    }
}
