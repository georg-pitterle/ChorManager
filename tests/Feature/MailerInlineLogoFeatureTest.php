<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Services\Mailer;
use PHPUnit\Framework\TestCase;

/**
 * Der Mailkopf traegt das Logo als `data:`-URI aus der Vorlage. Gmail entfernt solche
 * Quellen; verschickt werden darf es deshalb nur als eingebetteter Anhang.
 */
final class MailerInlineLogoFeatureTest extends TestCase
{
    private const PIXEL_BASE64 = 'iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mP8z8BQDwAEhQGAhKmMIQAAAABJRU5ErkJggg==';

    protected function setUp(): void
    {
        parent::setUp();

        $_ENV['SMTP_HOST'] = $_SERVER['SMTP_HOST'] = '';
        $_ENV['SMTP_FROM_EMAIL'] = $_SERVER['SMTP_FROM_EMAIL'] = 'noreply@chor.test';
    }

    private function logoBody(): string
    {
        return '<html><body><img src="data:image/png;base64,' . self::PIXEL_BASE64 . '" alt="Logo"></body></html>';
    }

    public function testLogoIsSentAsEmbeddedAttachmentInsteadOfDataUri(): void
    {
        $mailer = new Mailer();

        $mime = $mailer->buildMimeMessage('empfaenger@example.test', 'Newsletter', $this->logoBody());

        $this->assertStringNotContainsString('data:image', $mime);
        $this->assertStringContainsString('Content-ID: <', $mime);
        $this->assertStringContainsString('Content-Disposition: inline', $mime);
        $this->assertStringContainsString('multipart/related', $mime);
    }

    public function testEmbeddedImagesDoNotLeakIntoTheNextMail(): void
    {
        $mailer = new Mailer();

        $mailer->buildMimeMessage('erste@example.test', 'Erste', $this->logoBody());
        $mime = $mailer->buildMimeMessage('zweite@example.test', 'Zweite', '<p>Ohne Bild</p>');

        $this->assertStringNotContainsString('Content-ID: <', $mime);
        $this->assertStringNotContainsString('multipart/related', $mime);
    }

    public function testRecipientsDoNotAccumulateAcrossMails(): void
    {
        $mailer = new Mailer();

        $mailer->buildMimeMessage('erste@example.test', 'Erste', '<p>Inhalt</p>');
        $mime = $mailer->buildMimeMessage('zweite@example.test', 'Zweite', '<p>Inhalt</p>');

        $this->assertStringNotContainsString('erste@example.test', $mime);
        $this->assertStringContainsString('zweite@example.test', $mime);
    }
}
