<?php

declare(strict_types=1);

namespace App\Util;

/**
 * Hasht Passwörter - im Betrieb mit dem Standardaufwand, im Testlauf mit dem Minimum.
 *
 * Anlass: Ein bcrypt-Hash mit dem Standardaufwand kostet im Container rund 160 ms.
 * Genau das ist im Betrieb erwünscht: es macht das Durchprobieren gestohlener Hashes
 * teuer. Im Testlauf ist es verschenkte Zeit - die Suite legt in setUp() reihenweise
 * Personen an und verbrachte damit rund ein Viertel ihrer Laufzeit im Hashen von
 * Passwörtern, die kein Test je prüft.
 *
 * Der abgesenkte Aufwand hängt deshalb an APP_ENV und nicht an einer eigenen
 * Umgebungsvariable: eine solche wäre im Betrieb versehentlich setzbar, und ein zu
 * billig gehashtes Passwort fällt niemandem auf.
 */
final class PasswordHasher
{
    /**
     * Der kleinste Aufwand, den bcrypt zulässt. Er kostet unter einer Millisekunde und
     * ist ausschließlich für Testläufe gedacht.
     */
    public const TEST_COST = 4;

    public static function hash(string $plainPassword): string
    {
        $cost = self::costForCurrentEnvironment();

        if ($cost === null) {
            return password_hash($plainPassword, PASSWORD_DEFAULT);
        }

        return password_hash($plainPassword, PASSWORD_BCRYPT, ['cost' => $cost]);
    }

    /**
     * @return int|null Null bedeutet: den Standardaufwand von PASSWORD_DEFAULT nehmen.
     */
    public static function costForCurrentEnvironment(): ?int
    {
        $environment = $_ENV['APP_ENV'] ?? $_SERVER['APP_ENV'] ?? null;

        return self::costForEnvironment($environment === null ? null : (string) $environment);
    }

    /**
     * @return int|null Null bedeutet: den Standardaufwand von PASSWORD_DEFAULT nehmen.
     */
    public static function costForEnvironment(?string $environment): ?int
    {
        return $environment === 'test' ? self::TEST_COST : null;
    }
}
