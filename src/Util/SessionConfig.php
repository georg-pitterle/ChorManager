<?php

declare(strict_types=1);

namespace App\Util;

/**
 * Session storage location and start.
 *
 * PHP writes session files into the container's writable layer by default, so
 * every image update or container recreate discards them and logs every user
 * out. SESSION_SAVE_PATH points PHP at a directory that is backed by a named
 * volume in the production stack.
 */
class SessionConfig
{
    public const ENV_KEY = 'SESSION_SAVE_PATH';

    /**
     * Apply the configured session save path.
     *
     * An unset variable or an unusable path keeps the PHP default: a broken
     * override must not take sessions - and with them every login - down.
     *
     * @return bool True when the save path was applied.
     */
    public static function applySavePath(): bool
    {
        $configured = EnvHelper::readRaw(self::ENV_KEY);
        if ($configured === null) {
            return false;
        }

        $path = trim($configured);
        if ($path === '') {
            return false;
        }

        if (!is_dir($path)) {
            @mkdir($path, 0700, true);
        }

        if (!is_dir($path) || !is_writable($path)) {
            return false;
        }

        return ini_set('session.save_path', $path) !== false;
    }

    /**
     * Startet die Sitzung, falls noch keine läuft.
     *
     * Dieselben drei Zeilen standen an sechs Stellen: in fünf Middlewares und in
     * der Twig-Fabrik. Jede davon muss die Sitzung notfalls selbst starten, weil
     * sie auch einzeln läuft - im Test und über einen anderen Einstiegspunkt, wo
     * die äußerste Middleware gar nicht beteiligt ist. Sechs Abschriften
     * derselben Regel heißen aber sechs Stellen, an denen eine Korrektur
     * vergessen werden kann; hier steht sie einmal.
     *
     * Geprüft wird auf `PHP_SESSION_NONE` und nicht auf `!PHP_SESSION_ACTIVE`:
     * Ist die Sitzungsverwaltung abgeschaltet (`PHP_SESSION_DISABLED`), gibt es
     * nichts zu starten, und `session_start()` würde nur warnen.
     *
     * @return bool Ob danach eine Sitzung läuft.
     */
    public static function ensureStarted(): bool
    {
        if (session_status() === PHP_SESSION_NONE) {
            session_start();
        }

        return session_status() === PHP_SESSION_ACTIVE;
    }

    /**
     * Decide whether the session cookie must carry the Secure flag.
     *
     * APP_ENV is read via EnvHelper: Dotenv::safeLoad() only fills $_ENV/$_SERVER, so a value that
     * lives solely in the .env stays invisible to getenv() - the cookie would then ship without the
     * Secure flag in production. X-Forwarded-Proto is only honoured behind a trusted proxy, the
     * same rule AppUrlResolver applies, because any client can send that header itself.
     *
     * @param array<string, mixed> $serverParams
     */
    public static function shouldUseSecureCookie(array $serverParams): bool
    {
        if (AppEnvironment::isProduction()) {
            return true;
        }

        $https = strtolower(trim((string) ($serverParams['HTTPS'] ?? '')));
        if ($https !== '' && $https !== 'off') {
            return true;
        }

        $remoteAddress = trim((string) ($serverParams['REMOTE_ADDR'] ?? ''));
        if ($remoteAddress === '' || !ClientIpResolver::isTrustedProxy($remoteAddress)) {
            return false;
        }

        $forwardedProto = (string) ($serverParams['HTTP_X_FORWARDED_PROTO'] ?? '');
        $firstProto = strtolower(trim((string) explode(',', $forwardedProto)[0]));

        return $firstProto === 'https';
    }
}
