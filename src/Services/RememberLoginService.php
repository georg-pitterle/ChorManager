<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\RememberLogin;
use Psr\Http\Message\ServerRequestInterface as Request;

class RememberLoginService
{
    public const COOKIE_NAME = 'remember_login';

    private int $rememberDays;

    public function __construct()
    {
        $configuredDays = (int) \App\Util\EnvHelper::read('REMEMBER_ME_DAYS', '30');
        $this->rememberDays = max(1, $configuredDays);
    }

    public function issueForUser(int $userId, Request $request): string
    {
        $selector = bin2hex(random_bytes(9));
        $validator = bin2hex(random_bytes(32));
        $expiresAt = time() + ($this->rememberDays * 86400);

        RememberLogin::create([
            'user_id' => $userId,
            'selector' => $selector,
            'token_hash' => password_hash($validator, PASSWORD_DEFAULT),
            'expires_at' => date('Y-m-d H:i:s', $expiresAt),
            'created_at' => date('Y-m-d H:i:s'),
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
            return null;
        }

        /** @var RememberLogin|null $token */
        $token = RememberLogin::where('selector', $selector)->first();
        if (!$token) {
            return null;
        }

        if (strtotime((string) $token->expires_at) <= time()) {
            $token->delete();
            return null;
        }

        if (!password_verify($validator, (string) $token->token_hash)) {
            $token->delete();
            return null;
        }

        $token->last_used_at = date('Y-m-d H:i:s');
        $token->save();

        return $token;
    }

    public function rotateToken(RememberLogin $token, Request $request): string
    {
        $token->delete();

        return $this->issueForUser((int) $token->user_id, $request);
    }

    public function invalidateByCookieValue(string $cookieValue): void
    {
        [$selector] = $this->splitCookieValue($cookieValue);
        if (!$selector) {
            return;
        }

        RememberLogin::where('selector', $selector)->delete();
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
        $serverParams = $request->getServerParams();
        $ip = $serverParams['REMOTE_ADDR'] ?? null;
        if (!$ip || !is_string($ip)) {
            return null;
        }

        return mb_substr($ip, 0, 45);
    }
}
