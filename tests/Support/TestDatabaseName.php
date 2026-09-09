<?php

declare(strict_types=1);

namespace Tests\Support;

use RuntimeException;

/**
 * Leitet aus dem konfigurierten Datenbanknamen den Namen ab, gegen den ein Testlauf
 * arbeitet.
 *
 * Anlass: Bis hierher lief die Suite gegen dieselbe Datenbank wie die Entwicklung im
 * Browser. Zwei Läufe gleichzeitig - drei Sitzungen an einem Repository sind der
 * Normalfall - schrieben damit in denselben Bestand. Sichtbar wurde das als
 * sporadisch roter DatabaseLeakGuardTest: der Zählstand am Ende passte nicht mehr zum
 * Ausgangsstand, weil ein fremder Lauf dazwischen Zeilen angelegt und wieder entfernt
 * hatte. Im schlimmsten Fall traf es MysqldumpRunnerFeatureTest, der die ganze
 * Datenbank sichert und zurückspielt: sein Dump war dann inkonsistent und der
 * Rückspielvorgang setzte die Datenbank auf einen fremden Stand.
 *
 * Der Worker-Anteil im Namen hält die Läufe paralleler Testprozesse auseinander.
 */
final class TestDatabaseName
{
    /** Der Wächter in TestDatabaseGuard erkennt Testdatenbanken an dieser Endung. */
    public const SUFFIX = '_test';

    /** Ohne Angabe in der Umgebung fällt die Verbindung auf den DDEV-Standard zurück. */
    private const FALLBACK_BASE = 'db';

    /**
     * @param string      $configuredName Wert aus der Umgebung, etwa DB_DATABASE.
     * @param string|null $workerToken    Kennung des Testprozesses, etwa TEST_TOKEN aus paratest.
     *
     * @throws RuntimeException wenn die Worker-Kennung kein schlichter Bezeichner ist.
     */
    public static function forRun(string $configuredName, ?string $workerToken = null): string
    {
        $base = trim($configuredName);

        if ($base === '') {
            $base = self::FALLBACK_BASE;
        }

        // Ein Name, der schon auf _test endet, wird nicht ein zweites Mal ergänzt.
        if (!str_ends_with($base, self::SUFFIX)) {
            $base .= self::SUFFIX;
        }

        $token = self::normalizeToken($workerToken);

        return $token === null ? $base : $base . '_' . $token;
    }

    /**
     * @throws RuntimeException
     */
    private static function normalizeToken(?string $workerToken): ?string
    {
        $token = trim((string) $workerToken);

        if ($token === '') {
            return null;
        }

        // Der Name landet in "CREATE DATABASE `...`". Ein Backtick oder ein Semikolon
        // darin wäre kein Namensbestandteil mehr, sondern eine zweite Anweisung.
        if (preg_match('/^[A-Za-z0-9_]+$/', $token) !== 1) {
            throw new RuntimeException(sprintf(
                'Die Worker-Kennung "%s" ist kein schlichter Bezeichner.'
                . ' Erlaubt sind Buchstaben, Ziffern und Unterstriche.',
                $token
            ));
        }

        return $token;
    }
}
