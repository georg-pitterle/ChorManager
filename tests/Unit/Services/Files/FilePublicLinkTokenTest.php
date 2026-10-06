<?php

declare(strict_types=1);

namespace Tests\Unit\Services\Files;

use App\Services\Files\FileShareService;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * Der Token eines öffentlichen Datei-Links ist ein Bearer-Token: Wer ihn hat, lädt die
 * Datei ohne Anmeldung. Sein Muster entscheidet, was überhaupt bis zur Abfrage kommt -
 * und muss dieselbe Grenze ziehen wie bei Kalenderabo und Noten-Ordner
 * (CalendarSubscriptionService, WebdavAccessService): `\z` statt `$`, weil `$` in PCRE
 * einen abschließenden Zeilenumbruch durchgehen lässt.
 */
final class FilePublicLinkTokenTest extends TestCase
{
    #[DataProvider('rejectedTokenProvider')]
    public function testOnlyWellFormedTokensAreAccepted(string $candidate, string $why): void
    {
        $this->assertSame(0, preg_match(FileShareService::TOKEN_PATTERN, $candidate), $why);
    }

    /**
     * @return array<string, array{0: string, 1: string}>
     */
    public static function rejectedTokenProvider(): array
    {
        return [
            'leer' => ['', 'Ein leerer Token darf nie zu einem Link führen.'],
            'zu kurz' => [str_repeat('a', 31), 'Zu kurze Werte sind keine Token.'],
            'zu lang' => [str_repeat('a', 33), 'Zu lange Werte sind keine Token.'],
            'platzhalter' => [str_repeat('a', 31) . '%', 'SQL-Platzhalter dürfen nicht durchrutschen.'],
            'punkt' => [str_repeat('a', 31) . '.', 'Nur das base64url-Alphabet ist zulässig.'],
            'zeilenumbruch' => [str_repeat('a', 32) . "\n", 'Anhängsel nach dem Token sind ungültig.'],
        ];
    }

    public function testGeneratedTokenShapeMatchesThePattern(): void
    {
        // Dieselbe Erzeugung wie in createLink(), nur ohne Datenbank.
        $token = rtrim(strtr(base64_encode(random_bytes(24)), '+/', '-_'), '=');

        $this->assertSame(
            1,
            preg_match(FileShareService::TOKEN_PATTERN, $token),
            'Ein erzeugter Token muss die Route /s/{token} bedienen können.'
        );
    }
}
