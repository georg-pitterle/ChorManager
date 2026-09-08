<?php

declare(strict_types=1);

namespace Tests\Support;

use RuntimeException;

/**
 * Verhindert, dass ein Testlauf an einer Datenbank hängt, die keine Testdatenbank ist.
 *
 * Anlass: Die Testverbindung liest stumm `DB_HOST`/`DB_DATABASE` aus `.env`. Zeigt das
 * einmal woanders hin - eine kopierte `.env`, ein gesetzter Umgebungswert in der Shell -
 * schreibt die Suite in fremde Daten, ohne zu murren.
 *
 * Der Wächter steht bewusst in `tests/bootstrap.php` und nicht in `Tests\Unit\Bootstrap`:
 * fünfzehn Testklassen bauen ihre Eloquent-Verbindung selbst auf und liefen an einer
 * Prüfung in `Bootstrap` vorbei. Der Bootstrap dagegen läuft bei jedem Aufruf.
 */
final class TestDatabaseGuard
{
    /** Der DDEV-Standard heißt schlicht "db". */
    private const ALLOWED_NAMES = ['db', 'test', 'testing'];

    private const OVERRIDE_VARIABLE = 'ALLOW_NON_TEST_DATABASE';

    /**
     * @throws RuntimeException wenn der Name nicht nach einer Testdatenbank aussieht.
     */
    public static function assertTestDatabase(?string $database, ?string $override = null): void
    {
        if (self::isOverridden($override)) {
            return;
        }

        // Leer bedeutet: keine Angabe in der Umgebung, die Verbindung fällt auf "db" zurück.
        $name = trim((string) $database);

        if ($name === '' || self::looksLikeTestDatabase($name)) {
            return;
        }

        throw new RuntimeException(sprintf(
            'Die Testsuite soll gegen die Datenbank "%s" laufen. Das ist keine Testdatenbank.'
            . ' Erlaubt sind %s oder ein Name, der auf "_test" endet.'
            . ' Die Tests legen Daten an und leeren Tabellen - auf einem echten Bestand'
            . ' zerstört das Daten. Bitte DB_DATABASE in .env prüfen.'
            . ' Ist der Name wirklich gewollt, %s=1 setzen.',
            $name,
            '"' . implode('", "', self::ALLOWED_NAMES) . '"',
            self::OVERRIDE_VARIABLE
        ));
    }

    public static function looksLikeTestDatabase(string $name): bool
    {
        return in_array($name, self::ALLOWED_NAMES, true) || str_ends_with($name, '_test');
    }

    private static function isOverridden(?string $override): bool
    {
        return in_array($override, ['1', 'true', 'yes'], true);
    }
}
