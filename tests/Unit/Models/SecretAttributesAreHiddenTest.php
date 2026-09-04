<?php

declare(strict_types=1);

namespace Tests\Unit\Models;

use App\Models\CalendarSubscriptionToken;
use App\Models\InvitationToken;
use App\Models\PasswordReset;
use App\Models\RememberLogin;
use App\Models\User;
use App\Models\UserMailAccount;
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
            // Seit 20260901122000 gibt es beim Kalender-Abo keinen Klartext mehr.
            // Der Hash bleibt schützenswert: Er taugt zum Abgleich gegen eine
            // geratene Adresse und hat in Ausgaben nichts verloren.
            'Kalender-Abo' => [CalendarSubscriptionToken::class, 'token_hash', 'hash-wert'],
            // Das IMAP-Passwort liegt verschlüsselt in der Spalte. Verschlüsselt
            // heißt nicht harmlos: Wer den Wert mitsamt dem Schlüssel aus der
            // Umgebung hat, hat das Postfach. MailBadgeService und
            // RotateMailCredentialKeyCommand lesen die Eigenschaft direkt und
            // bleiben davon unberührt.
            'Postfach-Zugang' => [UserMailAccount::class, 'imap_password_enc', 'geheimer-wert'],
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
