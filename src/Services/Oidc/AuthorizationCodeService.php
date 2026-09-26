<?php

declare(strict_types=1);

namespace App\Services\Oidc;

use App\Models\OidcAccessToken;
use App\Models\OidcAuthCode;
use App\Models\OidcClient;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;

/**
 * Stellt Autorisierungscodes aus und löst sie ein - genau einmal.
 *
 * Gespeichert wird nur der SHA-256-Hash des Zufallswerts, wie bei
 * calendar_subscription_tokens. Deterministisch und ohne Salt, weil die Zeile
 * über einen Index gefunden werden muss; durchprobierbar ist der Wert mit 256
 * Bit Entropie ohnehin nicht.
 *
 * Die Einmaligkeit ist der sicherheitstragende Teil. Eine zweite Einlösung
 * desselben Codes bedeutet, dass ihn jemand mitgelesen hat - dann sind auch die
 * aus der ersten Einlösung stammenden Token verbrannt und werden widerrufen.
 * Deshalb wird die Zeile beim Einlösen nicht gelöscht, sondern mit `used_at`
 * markiert: Eine gelöschte Zeile sähe aus wie ein nie ausgestellter Code.
 */
class AuthorizationCodeService
{
    /** 60 Sekunden. Der Client löst den Code unmittelbar ein. */
    public const CODE_TTL_SECONDS = 60;

    public const CHALLENGE_METHOD = 'S256';

    public function __construct(private readonly LoggerInterface $logger = new NullLogger())
    {
    }

    public static function hashCode(string $code): string
    {
        return hash('sha256', $code);
    }

    /**
     * Stellt einen Code aus. Der zurückgegebene Klartext ist die einzige
     * Gelegenheit, ihn weiterzugeben.
     */
    public function issue(
        OidcClient $client,
        int $userId,
        string $redirectUri,
        string $scope,
        ?string $nonce,
        string $codeChallenge
    ): string {
        $code = bin2hex(random_bytes(32));

        OidcAuthCode::create([
            'code_hash' => self::hashCode($code),
            'client_id' => (string) $client->client_id,
            'user_id' => $userId,
            'redirect_uri' => $redirectUri,
            'scope' => $scope,
            'nonce' => $nonce,
            'code_challenge' => $codeChallenge,
            'code_challenge_method' => self::CHALLENGE_METHOD,
            'expires_at' => date('Y-m-d H:i:s', time() + self::CODE_TTL_SECONDS),
            'created_at' => date('Y-m-d H:i:s'),
        ]);

        $this->logger->info('OIDC authorization code granted.', [
            'event' => 'oidc.authorize.granted',
            'client_id' => (string) $client->client_id,
            'user_id' => $userId,
        ]);

        return $code;
    }

    /**
     * Löst einen Code ein.
     *
     * Jeder Fehlschlag meldet nach außen `invalid_grant` - welcher Teil nicht
     * stimmte, steht nur im Grund und damit nur im Log.
     *
     * @throws OidcProtocolException
     */
    public function redeem(
        string $code,
        string $clientId,
        string $redirectUri,
        string $codeVerifier
    ): OidcAuthCode {
        if ($code === '' || $codeVerifier === '') {
            throw new OidcProtocolException('invalid_grant', 'code oder code_verifier fehlt');
        }

        $stored = OidcAuthCode::query()->where('code_hash', self::hashCode($code))->first();
        if ($stored === null) {
            throw new OidcProtocolException('invalid_grant', 'unbekannter Code');
        }

        if ($stored->used_at !== null) {
            // Zweite Einlösung: Der Code war unterwegs sichtbar. Alles, was aus
            // ihm hervorging, ist damit ebenfalls verbrannt.
            $revoked = $this->revokeTokensFromCode($stored);

            $this->logger->warning('OIDC authorization code replayed.', [
                'event' => 'oidc.token.rejected',
                'reason' => 'code_replayed',
                'client_id' => (string) $stored->client_id,
                'user_id' => (int) $stored->user_id,
                'revoked_tokens' => $revoked,
            ]);

            throw new OidcProtocolException('invalid_grant', 'Code war bereits eingelöst');
        }

        if (!hash_equals((string) $stored->client_id, $clientId)) {
            throw new OidcProtocolException('invalid_grant', 'Code gehört zu einem anderen Client');
        }

        if (!hash_equals((string) $stored->redirect_uri, $redirectUri)) {
            throw new OidcProtocolException('invalid_grant', 'redirect_uri weicht von der Ausstellung ab');
        }

        if (strtotime((string) $stored->expires_at) < time()) {
            throw new OidcProtocolException('invalid_grant', 'Code ist abgelaufen');
        }

        if (!self::verifyChallenge((string) $stored->code_challenge, $codeVerifier)) {
            throw new OidcProtocolException('invalid_grant', 'code_verifier passt nicht zur code_challenge');
        }

        // Bedingtes Update statt Lesen-und-dann-Schreiben, wie beim Anspruch auf
        // einen Newsletter-Entwurf (NewsletterLockingService::acquireLock) oder
        // eine Warteschlangen-Zeile (MailDeliveryService::sendEntry): Zwischen der
        // Abfrage oben und dieser Markierung passt sonst ein zweiter Lauf. Beide
        // sahen einen offenen Code, beide kamen durch die Prüfungen, und beide
        // bekamen ein Zugriffstoken - die Einmaligkeit, auf der hier alles
        // aufbaut, galt dann nur ohne gleichzeitigen Zugriff. Schlimmer noch: Die
        // Wiedereinlösung unten, die die Token eines mitgelesenen Codes verbrennt,
        // trat in genau diesem Fall nie ein.
        //
        // Erst hier und nicht schon oben: Ein Code, dessen code_verifier nicht
        // passt, darf nicht verbraucht sein. Sonst genügte eine Anfrage mit
        // falschem Verifier, um dem berechtigten Klienten seinen Code zu nehmen.
        $now = date('Y-m-d H:i:s');
        $claimed = OidcAuthCode::query()
            ->whereKey($stored->id)
            ->whereNull('used_at')
            ->update(['used_at' => $now]);

        if ($claimed === 0) {
            $revoked = $this->revokeTokensFromCode($stored);

            $this->logger->warning('OIDC authorization code redeemed twice at once.', [
                'event' => 'oidc.token.rejected',
                'reason' => 'code_replayed',
                'client_id' => (string) $stored->client_id,
                'user_id' => (int) $stored->user_id,
                'revoked_tokens' => $revoked,
            ]);

            throw new OidcProtocolException('invalid_grant', 'Code war bereits eingelöst');
        }

        $stored->used_at = $now;
        $stored->syncOriginal();

        return $stored;
    }

    /**
     * S256: Die Challenge ist der base64url-kodierte SHA-256 des Verifiers.
     *
     * `plain` gibt es hier nicht und steht auch nicht im Discovery-Dokument -
     * damit stünde der Verifier im Klartext in der Authorize-Anfrage und PKCE
     * wäre wirkungslos.
     */
    public static function verifyChallenge(string $challenge, string $verifier): bool
    {
        $expected = rtrim(strtr(base64_encode(hash('sha256', $verifier, true)), '+/', '-_'), '=');

        return hash_equals($challenge, $expected);
    }

    /**
     * Widerruft Codes und Token eines Mitglieds - beim Sperren und beim
     * globalen Abmelden.
     *
     * @return array{codes:int, tokens:int}
     */
    public function revokeForUser(int $userId): array
    {
        $now = date('Y-m-d H:i:s');

        $codes = (int) OidcAuthCode::query()
            ->where('user_id', $userId)
            ->whereNull('used_at')
            ->update(['used_at' => $now]);

        $tokens = (int) OidcAccessToken::query()
            ->where('user_id', $userId)
            ->whereNull('revoked_at')
            ->update(['revoked_at' => $now]);

        return ['codes' => $codes, 'tokens' => $tokens];
    }

    /**
     * Widerruft alles - beim globalen Abmelden.
     *
     * @return array{codes:int, tokens:int}
     */
    public function revokeAll(): array
    {
        $now = date('Y-m-d H:i:s');

        $codes = (int) OidcAuthCode::query()->whereNull('used_at')->update(['used_at' => $now]);
        $tokens = (int) OidcAccessToken::query()->whereNull('revoked_at')->update(['revoked_at' => $now]);

        return ['codes' => $codes, 'tokens' => $tokens];
    }

    /**
     * Räumt abgelaufene Codes und Token weg - gelegentlich und billig, nach
     * dem Muster von RememberLoginService::clearExpiredTokens(), statt über
     * einen eigenen Cronjob.
     */
    public function clearExpired(): void
    {
        $cutoff = date('Y-m-d H:i:s', time() - 86400);

        OidcAuthCode::query()->where('expires_at', '<', $cutoff)->delete();
        OidcAccessToken::query()->where('expires_at', '<', $cutoff)->delete();
    }

    private function revokeTokensFromCode(OidcAuthCode $code): int
    {
        return (int) OidcAccessToken::query()
            ->where('auth_code_id', $code->id)
            ->whereNull('revoked_at')
            ->update(['revoked_at' => date('Y-m-d H:i:s')]);
    }
}
