<?php

declare(strict_types=1);

namespace Tests\Unit\Services;

use App\Services\ImapConnectionProbe;
use App\Util\BlockedHostException;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * Der Verbindungstest zum Postfach: ein Server meldet sich mit `* `, sonst nicht -
 * und das ohne Netz prüfbar.
 *
 * Den Socket öffnete der ProfileController selbst, mitten in der Methode. Hier
 * bekommen Auflösung und Verbindung zwei Closures; die Vorgabe baut genau den Code
 * von früher (aufgelöste Adresse statt Name, Zertifikatsprüfung gegen den Namen).
 */
final class ImapConnectionProbeTest extends TestCase
{
    public function testAGreetingStartingWithAnAsteriskIsASuccess(): void
    {
        $probe = new ImapConnectionProbe(
            static fn (string $host): string => '203.0.113.5',
            static fn (string $remote, string $peerName): ?string => "* OK ready\r\n"
        );

        self::assertSame(ImapConnectionProbe::OK, $probe->probe('mail.example.org', 993, 'ssl'));
    }

    /**
     * @return array<string, array{string}>
     */
    public static function answersThatAreNoImapGreeting(): array
    {
        return [
            'SMTP-Begrüßung' => ["220 smtp\r\n"],
            'leer' => [''],
        ];
    }

    #[DataProvider('answersThatAreNoImapGreeting')]
    public function testAnythingElseIsNotAnImapServer(string $answer): void
    {
        $probe = new ImapConnectionProbe(
            static fn (string $host): string => '203.0.113.5',
            static fn (string $remote, string $peerName): ?string => $answer
        );

        self::assertSame(ImapConnectionProbe::NO_GREETING, $probe->probe('mail.example.org', 993, 'ssl'));
    }

    public function testNoConnectionIsUnreachable(): void
    {
        $probe = new ImapConnectionProbe(
            static fn (string $host): string => '203.0.113.5',
            static fn (string $remote, string $peerName): ?string => null
        );

        self::assertSame(ImapConnectionProbe::UNREACHABLE, $probe->probe('mail.example.org', 993, 'ssl'));
    }

    public function testABlockedHostIsNeverContacted(): void
    {
        $probe = new ImapConnectionProbe(
            static function (string $host): string {
                throw new BlockedHostException('privat');
            },
            static function (string $remote, string $peerName): ?string {
                self::fail('Zu einem gesperrten Host darf keine Verbindung aufgebaut werden.');
            }
        );

        self::assertSame(ImapConnectionProbe::BLOCKED, $probe->probe('intern.example.org', 993, 'ssl'));
    }

    public function testTheConnectionGoesToTheResolvedAddressNotTheName(): void
    {
        $seen = [];
        $connect = static function (string $remote, string $peerName) use (&$seen): ?string {
            $seen[] = [$remote, $peerName];

            return "* OK\r\n";
        };

        (new ImapConnectionProbe(static fn (string $host): string => '203.0.113.5', $connect))
            ->probe('mail.example.org', 993, 'ssl');
        (new ImapConnectionProbe(static fn (string $host): string => '2001:db8::1', $connect))
            ->probe('mail.example.org', 143, 'none');

        self::assertSame(
            [
                ['ssl://203.0.113.5:993', 'mail.example.org'],
                ['tcp://[2001:db8::1]:143', 'mail.example.org'],
            ],
            $seen,
            'Verbunden wird mit der aufgelösten Adresse; der Name dient nur der Zertifikatsprüfung.'
        );
    }
}
