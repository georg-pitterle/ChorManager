<?php

declare(strict_types=1);

namespace App\Util;

/**
 * Merkt sich, ob PHP beim Start der Anfrage Dateien verworfen hat.
 *
 * Kommen mehr Dateien als `max_file_uploads`, behält PHP die ersten und wirft den
 * Rest weg. Der einzige Hinweis darauf ist eine Warnung, die noch vor dem ersten
 * Skript-Befehl entsteht - danach sieht `$_FILES` aus wie eine vollständige,
 * kleinere Auswahl. Deshalb liest `public/index.php` `error_get_last()` als
 * allererstes und legt das Ergebnis hier ab; die UploadFileCountMiddleware lehnt
 * die Anfrage dann ab, bevor ein Controller die Rumpf-Auswahl speichert.
 */
final class UploadFileCountGuard
{
    /**
     * Muss mit `max_file_uploads` im Dockerfile und in `.ddev/php/uploads.ini`
     * übereinstimmen; UploadFileCountGuardFeatureTest wacht darüber.
     */
    public const MAX_FILES = 50;

    private const STARTUP_MESSAGE = 'Maximum number of allowable file uploads has been exceeded';

    private static bool $exceeded = false;

    /**
     * @param array{type?: int, message?: string, file?: string, line?: int}|null $lastError
     */
    public static function record(?array $lastError): void
    {
        self::$exceeded = self::isExceededError($lastError);
    }

    /**
     * @param array{type?: int, message?: string, file?: string, line?: int}|null $lastError
     */
    public static function isExceededError(?array $lastError): bool
    {
        if ($lastError === null) {
            return false;
        }

        return str_contains((string) ($lastError['message'] ?? ''), self::STARTUP_MESSAGE);
    }

    public static function exceeded(): bool
    {
        return self::$exceeded;
    }

    public static function reset(): void
    {
        self::$exceeded = false;
    }

    public static function message(): string
    {
        return 'Es wurden mehr als ' . self::MAX_FILES . ' Dateien auf einmal hochgeladen. '
            . 'Es wurde nichts gespeichert - bitte in mehreren Schritten hochladen.';
    }
}
