<?php

declare(strict_types=1);

namespace Tests\Unit\Services;

use App\Services\CalendarSubscriptionService;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * Die datenbankfreien Zusicherungen rund um das Kalender-Token.
 *
 * Hintergrund: Der Token ist ein Bearer-Token für die Terminliste eines
 * Mitglieds und lag bis Migration 20260825120300 im Klartext in der Datenbank.
 * Gespeichert wird jetzt nur noch sein Hash - die Form des Tokens und die des
 * Hashes sind damit Teil des Vertrags zwischen Feed, Oberfläche und Schema.
 */
final class CalendarSubscriptionTokenTest extends TestCase
{
    public function testHashIsDeterministicSoTheFeedCanUseAnIndex(): void
    {
        $token = str_repeat('a1b2c3d4', 8);

        $this->assertSame(
            CalendarSubscriptionService::hashToken($token),
            CalendarSubscriptionService::hashToken($token)
        );
    }

    public function testHashDiffersBetweenTokens(): void
    {
        $this->assertNotSame(
            CalendarSubscriptionService::hashToken(str_repeat('a', 64)),
            CalendarSubscriptionService::hashToken(str_repeat('b', 64))
        );
    }

    public function testHashFitsTheSchemaColumn(): void
    {
        $hash = CalendarSubscriptionService::hashToken(str_repeat('f', 64));

        // char(64) in calendar_subscription_tokens.token_hash - ein längerer
        // Wert würde stillschweigend abgeschnitten und die Suche ins Leere laufen.
        $this->assertSame(64, strlen($hash));
        $this->assertMatchesRegularExpression('/^[a-f0-9]{64}$/', $hash);
    }

    public function testHashIsNotTheTokenItself(): void
    {
        $token = str_repeat('0123abcd', 8);

        $this->assertNotSame($token, CalendarSubscriptionService::hashToken($token));
    }

    #[DataProvider('rejectedTokenProvider')]
    public function testOnlyWellFormedTokensAreAccepted(string $candidate, string $why): void
    {
        $this->assertSame(
            0,
            preg_match(CalendarSubscriptionService::TOKEN_PATTERN, $candidate),
            $why
        );
    }

    /**
     * @return array<string, array{0: string, 1: string}>
     */
    public static function rejectedTokenProvider(): array
    {
        return [
            'leer' => ['', 'Ein leerer Token darf nie zu einer Zeile führen.'],
            'zu kurz' => [str_repeat('a', 63), 'Zu kurze Werte sind keine Token.'],
            'zu lang' => [str_repeat('a', 65), 'Zu lange Werte sind keine Token.'],
            'grossbuchstaben' => [str_repeat('A', 64), 'Der Token wird nur in Kleinschreibung erzeugt.'],
            'kein hex' => [str_repeat('g', 64), 'Nur Hex-Zeichen sind zulässig.'],
            'platzhalter' => [str_repeat('a', 63) . '%', 'SQL-Platzhalter dürfen nicht durchrutschen.'],
            'zeilenumbruch' => [str_repeat('a', 64) . "\n", 'Anhängsel nach dem Token sind ungültig.'],
        ];
    }

    public function testGeneratedTokenShapeMatchesThePattern(): void
    {
        // Dieselbe Erzeugung wie in rotateTokenForUser(), nur ohne Datenbank.
        $token = bin2hex(random_bytes(32));

        $this->assertSame(
            1,
            preg_match(CalendarSubscriptionService::TOKEN_PATTERN, $token),
            'Ein erzeugter Token muss die Route /events/export/{token}.ics bedienen können.'
        );
    }
}
