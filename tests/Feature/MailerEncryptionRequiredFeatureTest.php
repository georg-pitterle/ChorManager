<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Services\Mailer;
use PHPUnit\Framework\TestCase;

/**
 * SMTP ohne Verschlüsselung im Produktivbetrieb.
 *
 * Wer SMTP_HOST samt Benutzername und Passwort setzt, SMTP_ENCRYPTION aber vergisst,
 * verschickte bisher die SMTP-Zugangsdaten im Klartext - still, ohne Hinweis, und nur
 * durch das Wohlwollen des Servers gerettet, falls der von sich aus STARTTLS anbietet
 * (PHPMailer versucht es über SMTPAutoTLS, verlässt sich aber auf nichts). Im
 * Produktivbetrieb bricht der Versand jetzt ab, statt unverschlüsselt zu senden.
 *
 * In der Entwicklung bleibt alles wie bisher: Mailhog spricht kein TLS, und ein Abbruch
 * dort hielte nur die Arbeit auf.
 */
final class MailerEncryptionRequiredFeatureTest extends TestCase
{
    /** @var array<string, string|null> */
    private array $saved = [];

    private const KEYS = ['APP_ENV', 'SMTP_HOST', 'SMTP_PORT', 'SMTP_ENCRYPTION', 'SMTP_AUTH',
        'SMTP_USERNAME', 'SMTP_PASSWORD', 'SMTP_FROM_EMAIL', 'DISABLE_MAIL_SEND'];

    protected function setUp(): void
    {
        parent::setUp();

        foreach (self::KEYS as $key) {
            $this->saved[$key] = $_ENV[$key] ?? null;
        }
        $this->set('SMTP_FROM_EMAIL', 'noreply@chor.test');
        $this->set('DISABLE_MAIL_SEND', 'false');
        $this->set('SMTP_HOST', 'smtp.example.test');
        $this->set('SMTP_PORT', '587');
        $this->set('SMTP_AUTH', 'true');
        $this->set('SMTP_USERNAME', 'chor');
        $this->set('SMTP_PASSWORD', 'geheim');
    }

    protected function tearDown(): void
    {
        foreach (self::KEYS as $key) {
            $value = $this->saved[$key] ?? null;
            if ($value === null) {
                unset($_ENV[$key], $_SERVER[$key]);
                putenv($key);
                continue;
            }
            $this->set($key, $value);
        }

        parent::tearDown();
    }

    private function set(string $key, string $value): void
    {
        $_ENV[$key] = $_SERVER[$key] = $value;
        putenv($key . '=' . $value);
    }

    public function testProductionWithoutEncryptionDoesNotSend(): void
    {
        $this->set('APP_ENV', 'production');
        $this->set('SMTP_ENCRYPTION', 'none');

        $mailer = new Mailer();
        $result = $mailer->sendHtmlMailDetailed('empfaenger@example.test', 'Betreff', '<p>Inhalt</p>');

        $this->assertFalse($result['success']);
        $this->assertFalse($result['skipped'], 'Ein Abbruch ist kein übersprungener Versand.');
        // Der Abbruch kommt vor jedem Verbindungsversuch - sonst stünde hier der
        // Netzfehler des erfundenen Hosts, und der Test wäre auch ohne die Weigerung grün.
        $this->assertStringContainsString('invalid_config', (string) $mailer->getLastError());
        $this->assertStringNotContainsString('Could not connect', (string) $mailer->getLastError());
    }

    /**
     * Der Fehler ist dauerhaft, nicht vorübergehend: MailDeliveryService::classifyError()
     * erkennt `invalid_config` und legt die Mail sofort beiseite, statt sie dreimal
     * erneut zu versuchen. Eine fehlende Einstellung behebt sich nicht von selbst.
     */
    public function testTheFailureIsReportedAsAPermanentConfigurationError(): void
    {
        $this->set('APP_ENV', 'production');
        $this->set('SMTP_ENCRYPTION', '');

        $mailer = new Mailer();
        $mailer->sendHtmlMailDetailed('empfaenger@example.test', 'Betreff', '<p>Inhalt</p>');

        $this->assertStringContainsString('invalid_config', (string) $mailer->getLastError());
    }

    public function testProductionWithTlsOrSslIsNotBlocked(): void
    {
        $this->set('APP_ENV', 'production');

        foreach (['tls', 'ssl'] as $encryption) {
            $this->set('SMTP_ENCRYPTION', $encryption);
            $mailer = new Mailer();
            $mailer->sendHtmlMailDetailed('empfaenger@example.test', 'Betreff', '<p>Inhalt</p>');

            // Der Versand scheitert hier am nicht erreichbaren Server, nicht an der
            // Einstellung - genau das unterscheidet die beiden Fälle.
            $this->assertStringNotContainsString('invalid_config', (string) $mailer->getLastError(), $encryption);
        }
    }

    public function testDevelopmentWithoutEncryptionStaysAllowed(): void
    {
        $this->set('APP_ENV', 'development');
        $this->set('SMTP_ENCRYPTION', 'none');

        $mailer = new Mailer();
        $mailer->sendHtmlMailDetailed('empfaenger@example.test', 'Betreff', '<p>Inhalt</p>');

        $this->assertStringNotContainsString('invalid_config', (string) $mailer->getLastError());
    }

    /**
     * Ohne SMTP_HOST läuft der Versand über sendmail, also über einen lokalen Prozess
     * und nicht über das Netz. Dort gibt es keine Zugangsdaten zu schützen.
     */
    public function testSendmailInProductionIsNotBlocked(): void
    {
        $this->set('APP_ENV', 'production');
        $this->set('SMTP_HOST', '');
        $this->set('SMTP_ENCRYPTION', 'none');

        $mailer = new Mailer();
        $mailer->sendHtmlMailDetailed('empfaenger@example.test', 'Betreff', '<p>Inhalt</p>');

        $this->assertStringNotContainsString('invalid_config', (string) $mailer->getLastError());
    }

    /**
     * Ist der Versand ganz abgeschaltet, bleibt es beim übersprungenen Versand - die
     * Einstellung wird dann gar nicht gebraucht.
     */
    public function testDisabledSendingStaysSkippedAndNotBlocked(): void
    {
        $this->set('APP_ENV', 'production');
        $this->set('SMTP_ENCRYPTION', 'none');
        $this->set('DISABLE_MAIL_SEND', 'true');

        $result = (new Mailer())->sendHtmlMailDetailed('empfaenger@example.test', 'Betreff', '<p>Inhalt</p>');

        $this->assertTrue($result['success']);
        $this->assertTrue($result['skipped']);
    }
}
