<?php

declare(strict_types=1);

namespace Tests\Unit\Util;

use App\Util\PrimaryColor;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * Die Vereinsfarbe wird normalisiert und für Text auf hellem Grund abgedunkelt.
 *
 * Die Regeln standen im AppSettingController, und `MailBranding` holte sie von
 * dort - jeder Mailversand hing damit an einem HTTP-Controller. Als Util sind sie
 * ohne Controller und ohne Datenbank prüfbar; die Zusicherungen sind die aus
 * AppSettingFeatureTest, unverändert.
 */
final class PrimaryColorTest extends TestCase
{
    public function testNormalizePrimaryColorAcceptsValidHexAndAddsHash(): void
    {
        $this->assertSame('#E8A817', PrimaryColor::normalize('E8A817'));
        $this->assertSame('#112233', PrimaryColor::normalize('#112233'));
        $this->assertSame('#AABBCC', PrimaryColor::normalize('abc'));
    }

    public function testNormalizePrimaryColorFallsBackForInvalidValues(): void
    {
        $this->assertSame('#E8A817', PrimaryColor::normalize(''));
        $this->assertSame('#E8A817', PrimaryColor::normalize('not-a-color'));
        $this->assertSame('#E8A817', PrimaryColor::normalize('#12345'));
    }

    /**
     * @return array<string, array{string}>
     */
    public static function primaryColorProvider(): array
    {
        return [
            'Standardgelb' => ['#E8A817'],
            'reines Gelb' => ['#FFFF00'],
            'helles Cyan' => ['#00FFFF'],
            'Hellgrün' => ['#90EE90'],
            'Weiß' => ['#FFFFFF'],
            'dunkles Blau' => ['#1F3A5F'],
            'Schwarz' => ['#000000'],
        ];
    }

    #[DataProvider('primaryColorProvider')]
    public function testStrongPrimaryColorIsReadableOnPageBackground(string $color): void
    {
        $strong = PrimaryColor::strong($color);

        $this->assertMatchesRegularExpression('/^#[0-9A-F]{6}$/', $strong);
        $this->assertGreaterThanOrEqual(4.5, self::contrastRatio($strong, '#EEF2F7'));
        $this->assertGreaterThanOrEqual(4.5, self::contrastRatio($strong, '#FFFFFF'));
    }

    public function testStrongPrimaryColorKeepsAlreadyReadableColor(): void
    {
        $this->assertSame('#1F3A5F', PrimaryColor::strong('#1F3A5F'));
    }

    public function testStrongPrimaryColorKeepsHueOfDefaultYellow(): void
    {
        $strong = PrimaryColor::strong('#E8A817');
        [$red, $green, $blue] = sscanf($strong, '#%02X%02X%02X');

        $this->assertGreaterThan($green, $red);
        $this->assertGreaterThan($blue, $green);
    }

    public function testToRgbWritesTheChannelsAsDecimalList(): void
    {
        $this->assertSame('232, 168, 23', PrimaryColor::toRgb('#E8A817'));
        $this->assertSame('232, 168, 23', PrimaryColor::toRgb('not-a-color'));
    }

    private static function contrastRatio(string $foreground, string $background): float
    {
        $first = self::relativeLuminance($foreground);
        $second = self::relativeLuminance($background);

        return (max($first, $second) + 0.05) / (min($first, $second) + 0.05);
    }

    private static function relativeLuminance(string $hexColor): float
    {
        [$red, $green, $blue] = sscanf($hexColor, '#%02X%02X%02X');
        $channels = array_map(
            static function (int $value): float {
                $unit = $value / 255;

                return $unit <= 0.03928 ? $unit / 12.92 : (($unit + 0.055) / 1.055) ** 2.4;
            },
            [$red, $green, $blue]
        );

        return 0.2126 * $channels[0] + 0.7152 * $channels[1] + 0.0722 * $channels[2];
    }
}
