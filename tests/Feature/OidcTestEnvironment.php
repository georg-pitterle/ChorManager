<?php

declare(strict_types=1);

namespace Tests\Feature;

/**
 * Gemeinsamer Aufbau für die OIDC-Tests: Der Signierschlüssel-Tresor braucht
 * OIDC_SIGNING_KEY_SECRET, sonst schlägt schon der Konstruktor fehl - das ist
 * die gewollte Fail-Closed-Haltung, hier wird sie nur bedient.
 *
 * Gesetzt und zurückgesetzt wird je Test, damit ein paralleler Lauf nicht an
 * der Reihenfolge der Testklassen hängt.
 */
trait OidcTestEnvironment
{
    private const OIDC_SECRET_ENV = 'OIDC_SIGNING_KEY_SECRET';

    /** @var array{env: string|null, server: string|null, getenv: string|false}|null */
    private ?array $oidcSecretBackup = null;

    protected function enableOidcSigningSecret(): void
    {
        $this->oidcSecretBackup = [
            'env' => $_ENV[self::OIDC_SECRET_ENV] ?? null,
            'server' => $_SERVER[self::OIDC_SECRET_ENV] ?? null,
            'getenv' => getenv(self::OIDC_SECRET_ENV),
        ];

        $_ENV[self::OIDC_SECRET_ENV] = base64_encode(random_bytes(SODIUM_CRYPTO_SECRETBOX_KEYBYTES));
    }

    protected function restoreOidcSigningSecret(): void
    {
        if ($this->oidcSecretBackup === null) {
            return;
        }

        $backup = $this->oidcSecretBackup;
        $this->oidcSecretBackup = null;

        if ($backup['env'] === null) {
            unset($_ENV[self::OIDC_SECRET_ENV]);
        } else {
            $_ENV[self::OIDC_SECRET_ENV] = $backup['env'];
        }

        if ($backup['server'] === null) {
            unset($_SERVER[self::OIDC_SECRET_ENV]);
        } else {
            $_SERVER[self::OIDC_SECRET_ENV] = $backup['server'];
        }

        if ($backup['getenv'] === false) {
            putenv(self::OIDC_SECRET_ENV);
        } else {
            putenv(self::OIDC_SECRET_ENV . '=' . $backup['getenv']);
        }
    }
}
