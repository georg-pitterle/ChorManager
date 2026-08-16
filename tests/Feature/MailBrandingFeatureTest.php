<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Util\MailBranding;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * Die Markenfarbe ist pro Installation frei konfigurierbar. Die davon abgeleiteten Toene
 * muessen ihre Rolle deshalb bei jedem Farbwert erfuellen, nicht nur beim Standard-Amber.
 */
class MailBrandingFeatureTest extends TestCase
{
    /**
     * @return array<string, array{0: string}>
     */
    public static function brandColorProvider(): array
    {
        return [
            'Amber (Standard)' => ['#E8A817'],
            'helles Gelb' => ['#FFE066'],
            'Mittelgrün' => ['#2f6f4f'],
            'dunkles Blau' => ['#12233f'],
            'Kurzschreibweise' => ['#fc0'],
        ];
    }

    #[DataProvider('brandColorProvider')]
    public function testDerivedLinkColorMeetsAaOnWhite(string $brandColor): void
    {
        $linkColor = MailBranding::readableOnWhite($brandColor);

        $this->assertMatchesRegularExpression('/^#[0-9a-f]{6}$/', $linkColor);
        $this->assertGreaterThanOrEqual(
            4.5,
            $this->contrastWithWhite($linkColor),
            'Linkfarbe ' . $linkColor . ' aus ' . $brandColor . ' bleibt unter AA.'
        );
    }

    /**
     * Das Amber der Anwendung erreicht als Linkfarbe nur 2,9:1 und muss abgedunkelt werden.
     */
    public function testDefaultAmberIsDarkenedForLinkUse(): void
    {
        $this->assertLessThan(4.5, $this->contrastWithWhite('#c48e0f'));
        $this->assertNotSame('#e8a817', MailBranding::readableOnWhite('#E8A817'));
    }

    public function testTintAndEdgeStayLightEnoughForTextOnTop(): void
    {
        $tint = MailBranding::overWhite('#E8A817', 0.08);
        $edge = MailBranding::overWhite('#E8A817', 0.30);

        $this->assertSame('#fdf8ec', $tint);
        $this->assertSame('#f8e5b9', $edge);
        $this->assertGreaterThanOrEqual(4.5, $this->contrastOf('#1f2937', $tint));
    }

    public function testInvalidColorFallsBackToTheDefaultBrandColor(): void
    {
        $this->assertSame(
            MailBranding::readableOnWhite('#E8A817'),
            MailBranding::readableOnWhite('nicht-eine-farbe')
        );
    }

    public function testResolveDeliversEveryKeyTheMailLayoutNeeds(): void
    {
        $branding = MailBranding::resolve();

        foreach (['app_name', 'primary_color', 'primary_strong', 'primary_tint', 'primary_edge', 'logo_src'] as $key) {
            $this->assertArrayHasKey($key, $branding);
            $this->assertIsString($branding[$key]);
        }

        $this->assertNotSame('', $branding['app_name']);
        $this->assertMatchesRegularExpression('/^#[0-9a-fA-F]{6}$/', $branding['primary_color']);
    }

    private function contrastWithWhite(string $hexColor): float
    {
        return $this->contrastOf($hexColor, '#ffffff');
    }

    private function contrastOf(string $foreground, string $background): float
    {
        $lighter = max($this->luminance($foreground), $this->luminance($background));
        $darker = min($this->luminance($foreground), $this->luminance($background));

        return ($lighter + 0.05) / ($darker + 0.05);
    }

    private function luminance(string $hexColor): float
    {
        $hex = ltrim($hexColor, '#');
        $channels = [
            (int) hexdec(substr($hex, 0, 2)),
            (int) hexdec(substr($hex, 2, 2)),
            (int) hexdec(substr($hex, 4, 2)),
        ];

        $linear = array_map(static function (int $channel): float {
            $value = $channel / 255;

            return $value <= 0.03928 ? $value / 12.92 : (($value + 0.055) / 1.055) ** 2.4;
        }, $channels);

        return 0.2126 * $linear[0] + 0.7152 * $linear[1] + 0.0722 * $linear[2];
    }
}
