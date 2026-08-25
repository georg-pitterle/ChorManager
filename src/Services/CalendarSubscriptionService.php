<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\CalendarSubscriptionToken;

/**
 * Verwaltet die persönlichen Kalender-Abos.
 *
 * Der Token ist ein Bearer-Token: Wer ihn hat, sieht ohne Anmeldung die
 * Terminliste des Mitglieds. Gespeichert wird deshalb nur sein SHA-256-Hash
 * (Migration 20260825120300); der Klartext verlässt diese Klasse genau einmal,
 * beim Erzeugen. Bereits verteilte Abos aus der Zeit davor liegen noch im Klartext
 * vor und werden weiterhin akzeptiert und angezeigt, bis sie erneuert werden.
 */
class CalendarSubscriptionService
{
    /**
     * Ein Token ist ein 32-Byte-Zufallswert in Hex-Schreibweise.
     *
     * `\z` statt `$`: `$` lässt in PCRE einen abschließenden Zeilenumbruch
     * durchgehen, "<64 Hex-Zeichen>\n" wäre damit ein gültiger Token.
     */
    public const TOKEN_PATTERN = '/^[a-f0-9]{64}\z/';

    /**
     * Deterministischer Hash - der Feed muss die Zeile über einen Index finden.
     * Kein Salt und keine Streckung: Der Token ist ein Zufallswert mit 256 Bit
     * Entropie, kein Passwort, und damit nicht durchprobierbar.
     */
    public static function hashToken(string $token): string
    {
        return hash('sha256', $token);
    }

    /**
     * Erzeugt ein neues Abo und verwirft ein vorhandenes.
     *
     * Der zurückgegebene Klartext ist die einzige Gelegenheit, die Adresse
     * anzuzeigen - danach steht in der Datenbank nur noch der Hash.
     */
    public function rotateTokenForUser(int $userId): string
    {
        $token = bin2hex(random_bytes(32));

        CalendarSubscriptionToken::where('user_id', $userId)->delete();

        CalendarSubscriptionToken::create([
            'user_id' => $userId,
            'token' => null,
            'token_hash' => self::hashToken($token),
            'created_at' => date('Y-m-d H:i:s'),
        ]);

        return $token;
    }

    public function hasTokenForUser(int $userId): bool
    {
        return CalendarSubscriptionToken::where('user_id', $userId)->exists();
    }

    /**
     * Klartext eines Altbestands-Abos, sofern vorhanden.
     *
     * Nur Zeilen von vor Migration 20260825120300 tragen ihn noch. Für alles
     * danach ist die Adresse nicht mehr rekonstruierbar und die Oberfläche bietet
     * stattdessen das Neuerzeugen an.
     */
    public function findLegacyTokenForUser(int $userId): ?string
    {
        $subscription = CalendarSubscriptionToken::where('user_id', $userId)
            ->whereNotNull('token')
            ->first();

        if ($subscription === null) {
            return null;
        }

        $token = (string) $subscription->token;

        return preg_match(self::TOKEN_PATTERN, $token) === 1 ? $token : null;
    }

    public function findByToken(string $token): ?CalendarSubscriptionToken
    {
        if (preg_match(self::TOKEN_PATTERN, $token) !== 1) {
            return null;
        }

        $subscription = CalendarSubscriptionToken::where('token_hash', self::hashToken($token))->first();
        if ($subscription !== null) {
            return $subscription;
        }

        // Altbestand: vor 20260825120300 verteilte Abos liegen im Klartext vor.
        return CalendarSubscriptionToken::where('token', $token)->first();
    }
}
