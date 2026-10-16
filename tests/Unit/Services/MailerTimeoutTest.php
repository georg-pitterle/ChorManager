<?php

declare(strict_types=1);

namespace Tests\Unit\Services;

use App\Services\Mailer;
use PHPMailer\PHPMailer\PHPMailer;
use PHPUnit\Framework\TestCase;
use ReflectionProperty;

/**
 * Ein SMTP-Server, der die Verbindung annimmt und dann schweigt - typisch für
 * einen falschen Port, etwa STARTTLS gegen einen SSL-Port. PHPMailer wartete
 * darauf von Haus aus 300 Sekunden je Schritt, und `max_execution_time` greift
 * unter Linux nicht, weil Warten auf einen Socket nicht als Laufzeit zählt.
 * Lief das in einer Seitenanfrage, hielt sie die Sitzung fest; jeder weitere
 * Klick blieb daran hängen und belegte einen der fünf PHP-FPM-Prozesse, bis
 * die ganze Anwendung stand.
 */
final class MailerTimeoutTest extends TestCase
{
    private const ENV_KEYS = [
        'SMTP_HOST', 'SMTP_PORT', 'SMTP_AUTH', 'SMTP_ENCRYPTION', 'SMTP_TIMEOUT', 'DISABLE_MAIL_SEND', 'APP_ENV',
    ];

    /** @var array<string, string|null> */
    private array $originalEnv = [];

    protected function setUp(): void
    {
        parent::setUp();
        foreach (self::ENV_KEYS as $key) {
            $this->originalEnv[$key] = $_ENV[$key] ?? null;
        }
    }

    protected function tearDown(): void
    {
        foreach ($this->originalEnv as $key => $value) {
            if ($value === null) {
                unset($_ENV[$key]);
            } else {
                $_ENV[$key] = $value;
            }
        }

        parent::tearDown();
    }

    public function testSilentServerEndsAsAnOrdinaryFailureAfterTheTimeout(): void
    {
        // Lauscht, nimmt aber nie an und sagt nie etwas: Der Kernel schließt den
        // Verbindungsaufbau trotzdem ab, der Mailer wartet dann auf den Gruß.
        $server = stream_socket_server('tcp://127.0.0.1:0', $errorCode, $errorMessage);
        $this->assertNotFalse($server, $errorMessage);
        $port = (int) substr((string) strrchr((string) stream_socket_get_name($server, false), ':'), 1);

        $this->useSmtp($port, '1');

        $mailer = new Mailer();
        $startedAt = microtime(true);
        $result = $mailer->sendHtmlMailDetailed('mitglied@example.test', 'Probe', '<p>Probe</p>');
        $elapsed = microtime(true) - $startedAt;

        fclose($server);

        $this->assertFalse($result['success']);
        $this->assertNotNull($mailer->getLastError());
        $this->assertLessThan(5.0, $elapsed, 'Der Versand muss nach dem Timeout aufgeben.');
    }

    public function testWithoutSettingTheTimeoutIsThirtySeconds(): void
    {
        $this->useSmtp(2525, null);

        $phpMailer = $this->phpMailerOf(new Mailer());

        $this->assertSame(30, $phpMailer->Timeout);
        $this->assertSame(60, $phpMailer->getSMTPInstance()->Timelimit);
    }

    public function testConfiguredTimeoutAlsoBoundsTheWaitForEachResponse(): void
    {
        $this->useSmtp(2525, '10');

        $phpMailer = $this->phpMailerOf(new Mailer());

        $this->assertSame(10, $phpMailer->Timeout);
        $this->assertSame(20, $phpMailer->getSMTPInstance()->Timelimit);
    }

    public function testUnusableTimeoutFallsBackToTheDefault(): void
    {
        $this->useSmtp(2525, '0');

        $this->assertSame(30, $this->phpMailerOf(new Mailer())->Timeout);
    }

    private function useSmtp(int $port, ?string $timeout): void
    {
        $_ENV['SMTP_HOST'] = '127.0.0.1';
        $_ENV['SMTP_PORT'] = (string) $port;
        $_ENV['SMTP_AUTH'] = '0';
        $_ENV['SMTP_ENCRYPTION'] = 'none';
        $_ENV['DISABLE_MAIL_SEND'] = 'false';
        $_ENV['APP_ENV'] = 'test';
        if ($timeout === null) {
            unset($_ENV['SMTP_TIMEOUT']);
        } else {
            $_ENV['SMTP_TIMEOUT'] = $timeout;
        }
    }

    private function phpMailerOf(Mailer $mailer): PHPMailer
    {
        $property = new ReflectionProperty(Mailer::class, 'mail');

        return $property->getValue($mailer);
    }
}
