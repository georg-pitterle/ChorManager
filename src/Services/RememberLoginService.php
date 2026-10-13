<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\RememberLogin;
use App\Util\ClientIpResolver;
use App\Util\PasswordHasher;
use Psr\Http\Message\ServerRequestInterface as Request;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;

class RememberLoginService
{
    public const COOKIE_NAME = 'remember_login';

    private int $rememberDays;
    private int $maxDays;
    private LoggerInterface $logger;

    public function __construct(?LoggerInterface $logger = null)
    {
        $configuredDays = (int) \App\Util\EnvHelper::read('REMEMBER_ME_DAYS', '30');
        $this->rememberDays = max(1, $configuredDays);

        // Absolute Höchstdauer einer Anmeldekette. Nie kleiner als die
        // Einzelfrist: Eine Obergrenze unterhalb von REMEMBER_ME_DAYS ließe die
        // kürzere der beiden still gewinnen, und die Einstellung, die man
        // gerade angefasst hat, wäre die wirkungslose.
        $configuredMaxDays = (int) \App\Util\EnvHelper::read('REMEMBER_ME_MAX_DAYS', '180');
        $this->maxDays = max($this->rememberDays, $configuredMaxDays);

        $this->logger = $logger ?? new NullLogger();
    }

    /**
     * Stellt ein Token aus und gibt den Cookie-Wert zurück.
     *
     * `$chainStartedAt` trägt den Beginn der Anmeldekette. Bei einer frischen
     * Anmeldung ist das jetzt; eine Drehung gibt den Wert des alten Tokens
     * weiter, damit die Obergrenze den Tausch überlebt.
     */
    public function issueForUser(int $userId, Request $request, ?string $chainStartedAt = null): string
    {
        $selector = bin2hex(random_bytes(9));
        $validator = bin2hex(random_bytes(32));

        $now = time();
        $chainStart = $chainStartedAt === null ? $now : (strtotime($chainStartedAt) ?: $now);

        // Am Ende der Kette gekappt. Dadurch räumt `clearExpiredTokens()` eine
        // ausgelaufene Kette von selbst weg und braucht keine zweite Bedingung,
        // und ein Token verspricht nie eine Gültigkeit, die die Obergrenze
        // ohnehin nicht mehr deckt.
        $expiresAt = min($now + ($this->rememberDays * 86400), $chainStart + ($this->maxDays * 86400));

        RememberLogin::create([
            'user_id' => $userId,
            'selector' => $selector,
            'token_hash' => PasswordHasher::hash($validator),
            'expires_at' => date('Y-m-d H:i:s', $expiresAt),
            'created_at' => date('Y-m-d H:i:s', $now),
            'chain_started_at' => date('Y-m-d H:i:s', $chainStart),
            'last_used_at' => null,
            'user_agent' => $this->getUserAgent($request),
            'ip_address' => $this->getIpAddress($request)
        ]);

        return $selector . ':' . $validator;
    }

    public function validateCookieValue(string $cookieValue): ?RememberLogin
    {
        [$selector, $validator] = $this->splitCookieValue($cookieValue);

        if (!$selector || !$validator) {
            $this->logRejected('malformed');
            return null;
        }

        /** @var RememberLogin|null $token */
        $token = RememberLogin::where('selector', $selector)->first();
        if (!$token) {
            $this->logRejected('unknown_token');
            return null;
        }

        if (strtotime((string) $token->expires_at) <= time()) {
            $token->delete();
            $this->logRejected('expired');
            return null;
        }

        // Die Obergrenze der ganzen Kette, unabhängig davon, wie frisch dieses
        // einzelne Token ist. `expires_at` wird beim Ausstellen zwar schon
        // gekappt, aber das deckt nur Ketten ab, die unter dieser Regel
        // entstanden sind: Bestandszeilen tragen eine Frist aus der Zeit ohne
        // Obergrenze, und ein Datenbankeintrag von Hand wäre ebenfalls nicht
        // gekappt. Geprüft wird deshalb hier, wo es darauf ankommt.
        if ($this->chainExpired($token)) {
            $token->delete();
            $this->logRejected('chain_expired');
            return null;
        }

        if (!password_verify($validator, (string) $token->token_hash)) {
            $token->delete();
            $this->logRejected('invalid_token');
            return null;
        }

        $token->last_used_at = date('Y-m-d H:i:s');
        $token->save();

        $userId = (int) $token->user_id;
        $this->logger->info('Remember-me token accepted.', [
            'event' => 'auth.remember_me.used',
            'user_id' => $userId,
        ]);

        return $token;
    }

    private function logRejected(string $reason): void
    {
        $this->logger->info('Remember-me token rejected.', [
            'event' => 'auth.remember_me.rejected',
            'reason' => $reason,
        ]);
    }

    /**
     * Dreht das Token weiter und gibt den neuen Cookie-Wert zurück.
     *
     * Der Beginn der Kette wandert mit. Ohne diese Übernahme begänne sie bei
     * jeder Drehung neu, und die Obergrenze wäre wirkungslos - genau der
     * Zustand, den sie ersetzt.
     */
    public function rotateToken(RememberLogin $token, Request $request): string
    {
        $chainStartedAt = self::chainStartOf($token);
        $token->delete();

        return $this->issueForUser((int) $token->user_id, $request, $chainStartedAt);
    }

    /**
     * Ist die Anmeldekette dieses Tokens über der Höchstdauer?
     */
    private function chainExpired(RememberLogin $token): bool
    {
        $chainStart = strtotime(self::chainStartOf($token));

        // Ein unlesbarer Beginn sperrt. Anders als bei einem fehlenden Zahldatum
        // im Kassabuch ist die sichere Richtung hier das Abweisen: Wer sich neu
        // anmeldet, bekommt eine frische Kette, es geht also nichts verloren.
        if ($chainStart === false) {
            return true;
        }

        return $chainStart + ($this->maxDays * 86400) <= time();
    }

    /**
     * Beginn der Kette als Y-m-d-H:i:s-String.
     *
     * Bestandszeilen aus der Zeit vor Migration 20261013120000 konnten die
     * Spalte nicht tragen; dort greift ersatzweise `created_at`, also derselbe
     * Wert, den die Migration beim Nachtragen gesetzt hat.
     */
    private static function chainStartOf(RememberLogin $token): string
    {
        $value = $token->chain_started_at ?? $token->created_at;

        if ($value instanceof \DateTimeInterface) {
            return $value->format('Y-m-d H:i:s');
        }

        return (string) $value;
    }

    public function invalidateByCookieValue(string $cookieValue): void
    {
        [$selector] = $this->splitCookieValue($cookieValue);
        if (!$selector) {
            return;
        }

        RememberLogin::where('selector', $selector)->delete();
    }

    /**
     * Verwirft jedes Angemeldet-bleiben-Token eines Kontos und liefert deren Anzahl.
     *
     * Gegenstück zu invalidateByCookieValue(), das nur das Token der eigenen
     * Sitzung trifft: Nach einem Passwortwechsel müssen auch die Geräte
     * hinausfliegen, deren Cookie hier gerade nicht vorliegt.
     */
    public function invalidateAllForUser(int $userId): int
    {
        if ($userId <= 0) {
            return 0;
        }

        return RememberLogin::where('user_id', $userId)->delete();
    }

    public function clearExpiredTokens(): void
    {
        RememberLogin::where('expires_at', '<=', date('Y-m-d H:i:s'))->delete();
    }

    public function setRememberCookie(string $value): void
    {
        $maxAge = $this->rememberDays * 86400;

        setcookie(
            self::COOKIE_NAME,
            $value,
            [
                'expires' => time() + $maxAge,
                'path' => '/',
                'secure' => $this->shouldUseSecureCookie(),
                'httponly' => true,
                'samesite' => 'Lax'
            ]
        );
    }

    public function clearRememberCookie(): void
    {
        setcookie(
            self::COOKIE_NAME,
            '',
            [
                'expires' => time() - 42000,
                'path' => '/',
                'secure' => $this->shouldUseSecureCookie(),
                'httponly' => true,
                'samesite' => 'Lax'
            ]
        );
    }

    private function splitCookieValue(string $cookieValue): array
    {
        $parts = explode(':', $cookieValue, 2);
        if (count($parts) !== 2) {
            return [null, null];
        }

        $selector = preg_match('/^[a-f0-9]{18}$/', $parts[0]) ? $parts[0] : null;
        $validator = preg_match('/^[a-f0-9]{64}$/', $parts[1]) ? $parts[1] : null;

        return [$selector, $validator];
    }

    private function shouldUseSecureCookie(): bool
    {
        if (\App\Util\EnvHelper::read('APP_ENV', 'development') === 'production') {
            return true;
        }

        if (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') {
            return true;
        }

        return false;
    }

    private function getUserAgent(Request $request): ?string
    {
        $userAgent = trim($request->getHeaderLine('User-Agent'));
        if ($userAgent === '') {
            return null;
        }

        return mb_substr($userAgent, 0, 255);
    }

    private function getIpAddress(Request $request): ?string
    {
        $ip = ClientIpResolver::resolve($request);
        if ($ip === 'unknown') {
            return null;
        }

        return mb_substr($ip, 0, 45);
    }
}
