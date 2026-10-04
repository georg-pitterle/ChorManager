<?php

declare(strict_types=1);

namespace App\Services\Oidc;

use App\Models\OidcAccessToken;
use App\Models\OidcAuthCode;

/**
 * Stellt Zugriffstoken für /oidc/userinfo aus und löst sie wieder auf.
 *
 * Gespeichert wird nur der SHA-256-Hash. Kurzlebig (fünf Minuten) und ohne
 * Erneuerungstoken: `user_oidc` kommt ohne aus, und was es nicht gibt, muss
 * auch nicht widerrufen werden.
 */
class AccessTokenService
{
    public const TOKEN_TTL_SECONDS = 300;

    /**
     * Der Client-Dienst kommt mit einer Vorgabe, weil der Token-Dienst an
     * mehreren Stellen ohne Container gebaut wird (Tests, CLI). Über den
     * Container bekommt er die registrierte Instanz.
     */
    public function __construct(
        private readonly OidcClientService $clients = new OidcClientService()
    ) {
    }

    public static function hashToken(string $token): string
    {
        return hash('sha256', $token);
    }

    /**
     * Der zurückgegebene Klartext ist die einzige Gelegenheit, ihn
     * weiterzugeben.
     */
    public function issueForCode(OidcAuthCode $code): string
    {
        $token = bin2hex(random_bytes(32));

        OidcAccessToken::create([
            'token_hash' => self::hashToken($token),
            'client_id' => (string) $code->client_id,
            'user_id' => (int) $code->user_id,
            'auth_code_id' => (int) $code->id,
            'scope' => (string) $code->scope,
            'expires_at' => date('Y-m-d H:i:s', time() + self::TOKEN_TTL_SECONDS),
            'created_at' => date('Y-m-d H:i:s'),
        ]);

        return $token;
    }

    /**
     * Löst ein Bearer-Token auf. Abgelaufen, widerrufen oder von einem
     * abgeschalteten Client heißt: kein Treffer.
     */
    public function resolve(string $token): ?OidcAccessToken
    {
        if ($token === '') {
            return null;
        }

        $stored = OidcAccessToken::query()
            ->where('token_hash', self::hashToken($token))
            ->whereNull('revoked_at')
            ->first();

        if ($stored === null) {
            return null;
        }

        if (strtotime((string) $stored->expires_at) < time()) {
            return null;
        }

        // Der Client muss es noch geben und aktiv sein. Ohne diese Prüfung
        // wirkte das Abschalten einer angeschlossenen Anwendung erst, wenn ihre
        // bereits ausgestellten Token abgelaufen waren - bis zu fünf Minuten
        // später. Ein Notaus, der fünf Minuten braucht, ist keiner.
        if ($this->clients->findActiveClient((string) $stored->client_id) === null) {
            return null;
        }

        return $stored;
    }
}
