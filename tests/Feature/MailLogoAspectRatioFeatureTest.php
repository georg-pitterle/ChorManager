<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Util\MailBranding;
use PHPUnit\Framework\TestCase;
use Slim\Views\Twig;

/**
 * Das Logo im Mailkopf stand auf festen 56x56 Pixeln. Das mitgelieferte Standardlogo ist
 * quadratisch und sah deshalb richtig aus - jedes andere Seitenverhältnis wurde in dieses
 * Quadrat gequetscht. Geprüft wird darum das Seitenverhältnis, nicht die absolute Größe.
 */
final class MailLogoAspectRatioFeatureTest extends TestCase
{
    private function pngBytes(int $width, int $height): string
    {
        $image = imagecreatetruecolor($width, $height);
        self::assertNotFalse($image, 'GD konnte kein Testbild anlegen.');

        ob_start();
        imagepng($image);

        return (string) ob_get_clean();
    }

    /**
     * @param array<string, mixed> $context
     */
    private function renderNewsletter(array $context): string
    {
        $twig = Twig::create(dirname(__DIR__, 2) . '/templates');

        return $twig->fetch('emails/newsletter.twig', array_merge([
            'subject' => 'Probeausgabe',
            'content_html' => '<p>Inhalt</p>',
            'eyebrow_label' => 'Projektchor',
            'browse_url' => 'https://chor.example.org/newsletters/1/preview',
            'include_browse_link' => false,
            'app_name' => 'Chor-Manager',
            'primary_color' => '#E8A817',
            'primary_strong' => '#c48e0f',
            'primary_tint' => '#fdf8ec',
            'primary_edge' => '#f8e5b9',
            'logo_src' => 'data:image/png;base64,AAAA',
        ], $context));
    }

    /**
     * Das mitgelieferte Logo ist 512x512 - es muss den Kopfbereich weiter genauso füllen
     * wie bisher, sonst verschiebt der Fix das Erscheinungsbild jeder bestehenden Installation.
     */
    public function testSquareLogoKeepsTheFullBoxHeight(): void
    {
        $this->assertSame([56, 56], MailBranding::fitLogoBox($this->pngBytes(512, 512)));
    }

    public function testWideLogoKeepsItsAspectRatio(): void
    {
        [$width, $height] = MailBranding::fitLogoBox($this->pngBytes(1200, 300));

        $this->assertSame([200, 50], [$width, $height]);
        $this->assertEqualsWithDelta(1200 / 300, $width / $height, 0.05, 'Seitenverhältnis verzerrt.');
    }

    public function testTallLogoKeepsItsAspectRatio(): void
    {
        [$width, $height] = MailBranding::fitLogoBox($this->pngBytes(300, 1200));

        $this->assertSame(56, $height, 'Ein hohes Logo darf den Kopfbereich nicht sprengen.');
        $this->assertSame(14, $width);
    }

    /**
     * Ein sehr breites Banner muss auch in der Breite gedeckelt werden: die Mailkarte ist
     * 600 Pixel breit, davon gehen zweimal 40 Pixel Innenabstand ab.
     */
    public function testVeryWideLogoStaysInsideTheCardWidth(): void
    {
        [$width, $height] = MailBranding::fitLogoBox($this->pngBytes(4000, 200));

        $this->assertSame(200, $width);
        $this->assertSame(10, $height);
    }

    public function testUnreadableImageFallsBackToTheSquare(): void
    {
        $this->assertSame([56, 56], MailBranding::fitLogoBox('kein Bild'));
        $this->assertSame([56, 56], MailBranding::fitLogoBox(''));
    }

    /**
     * Ohne Datenbank greift resolve() auf das mitgelieferte Logo zurück. Die Maße müssen
     * trotzdem im Kontext stehen, sonst kommen sie nie in der Vorlage an.
     */
    public function testResolveExposesTheLogoBoxToTheTemplates(): void
    {
        $branding = MailBranding::resolve();

        $this->assertSame(56, $branding['logo_width']);
        $this->assertSame(56, $branding['logo_height']);
    }

    public function testRenderedHeaderUsesTheResolvedBoxInsteadOfFixedPixels(): void
    {
        $html = $this->renderNewsletter(['logo_width' => 200, 'logo_height' => 50]);

        $this->assertStringContainsString('width="200"', $html);
        $this->assertStringContainsString('height="50"', $html);
        $this->assertStringContainsString('width:200px', $html);
        $this->assertStringContainsString('height:50px', $html);
        $this->assertStringNotContainsString('height:56px', $html);
    }
}
