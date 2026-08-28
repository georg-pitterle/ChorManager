<?php

declare(strict_types=1);

namespace Tests\Unit\Models;

use App\Models\CalendarSubscriptionToken;
use App\Models\InvitationToken;
use App\Models\PasswordReset;
use App\Models\RememberLogin;
use App\Models\User;
use PHPUnit\Framework\TestCase;

/**
 * Geheimnisse dürfen nicht über die Serialisierung eines Modells nach draußen
 * gelangen - weder in JSON-Antworten noch in Log- oder Fehlerausgaben, die ein
 * Modell mitschreiben.
 */
final class SecretAttributesAreHiddenTest extends TestCase
{
    public function testPasswortHashLandetNichtInDerSerialisierung(): void
    {
        $user = new User();
        $user->setRawAttributes([
            'id' => 1,
            'email' => 'saenger@example.test',
            'password' => '$2y$10$abcdefghijklmnopqrstuv',
            'first_name' => 'Anna',
            'last_name' => 'Bergmüller',
            'is_active' => 1,
        ]);

        $serialized = $user->toArray();

        self::assertArrayNotHasKey('password', $serialized);
        self::assertSame('saenger@example.test', $serialized['email']);
        self::assertSame('$2y$10$abcdefghijklmnopqrstuv', $user->password, 'Der Login-Abgleich braucht den Hash.');
    }

    /**
     * @return array<string, array{class-string, string, string}>
     */
    public static function secretProvider(): array
    {
        return [
            'Angemeldet bleiben' => [RememberLogin::class, 'token_hash', 'hash-wert'],
            'Einladung' => [InvitationToken::class, 'token_hash', 'hash-wert'],
            'Passwort zurücksetzen' => [PasswordReset::class, 'token', 'klartext-token'],
            'Kalender-Abo' => [CalendarSubscriptionToken::class, 'token', 'klartext-token'],
        ];
    }

    /**
     * @param class-string $class
     */
    #[\PHPUnit\Framework\Attributes\DataProvider('secretProvider')]
    public function testTokenBleibenAusDerSerialisierung(string $class, string $attribute, string $value): void
    {
        $model = new $class();
        $model->setRawAttributes(['id' => 1, $attribute => $value]);

        self::assertArrayNotHasKey($attribute, $model->toArray());
        self::assertSame($value, $model->getAttribute($attribute), 'Der Code selbst muss weiter herankommen.');
    }
}
