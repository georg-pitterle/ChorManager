<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Services\Mailer;
use PHPUnit\Framework\TestCase;

/**
 * Jede Mail geht als `multipart/alternative` raus: HTML und daneben eine Textfassung für
 * Programme, die kein HTML anzeigen. Die Textfassung entstand aus `strip_tags()` und trug
 * damit den gesamten CSS-Block von `templates/emails/_layout.twig` als Fließtext, gefolgt
 * von allen Absätzen ohne einen einzigen Umbruch.
 */
final class MailerPlainTextAlternativeFeatureTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        $_ENV['SMTP_HOST'] = $_SERVER['SMTP_HOST'] = '';
        $_ENV['SMTP_FROM_EMAIL'] = $_SERVER['SMTP_FROM_EMAIL'] = 'noreply@chor.test';
    }

    private function layoutLikeBody(): string
    {
        return '<!DOCTYPE html><html lang="de"><head>'
            . '<title>Erinnerung</title>'
            . '<style>body, table, td, a { -webkit-text-size-adjust: 100%; }'
            . ' .cm-card { width: 100% !important; border-radius: 0 !important; }</style>'
            . '</head><body><p>Hallo Georg</p><p>Die Probe beginnt um 19:30.</p></body></html>';
    }

    public function testPlainTextPartCarriesNoStylesheet(): void
    {
        $mailer = new Mailer();

        $mime = $mailer->buildMimeMessage('empfaenger@example.test', 'Erinnerung', $this->layoutLikeBody());
        $plain = self::plainPartOf($mime);

        $this->assertStringNotContainsString('text-size-adjust', $plain);
        $this->assertStringNotContainsString('!important', $plain);
        $this->assertStringNotContainsString('cm-card', $plain);
    }

    public function testPlainTextPartKeepsTheReadableContent(): void
    {
        $mailer = new Mailer();

        $mime = $mailer->buildMimeMessage('empfaenger@example.test', 'Erinnerung', $this->layoutLikeBody());
        $plain = self::plainPartOf($mime);

        $this->assertStringContainsString('Hallo Georg', $plain);
        $this->assertStringContainsString('Die Probe beginnt um 19:30.', $plain);
        // Die Absätze stehen auf eigenen Zeilen, nicht aneinandergeklebt.
        $this->assertStringNotContainsString('Hallo GeorgDie Probe', $plain);
    }

    /**
     * Der `text/plain`-Teil der fertigen MIME-Nachricht, quoted-printable aufgelöst.
     */
    private static function plainPartOf(string $mime): string
    {
        $position = strpos($mime, 'Content-Type: text/plain');
        self::assertNotFalse($position, 'Die Nachricht trägt keinen text/plain-Teil.');

        $rest = substr($mime, $position);
        $end = strpos($rest, 'Content-Type: text/html');

        return quoted_printable_decode($end === false ? $rest : substr($rest, 0, $end));
    }
}
