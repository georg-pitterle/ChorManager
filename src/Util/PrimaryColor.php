<?php

declare(strict_types=1);

namespace App\Util;

/**
 * Die Vereinsfarbe: Normalisieren, als RGB-Liste schreiben und für Text auf
 * hellem Grund abdunkeln.
 *
 * Die Regeln standen im AppSettingController, und `MailBranding` holte sie von
 * dort - jeder Mailversand, auch der aus der Kommandozeile, hing damit an einem
 * HTTP-Controller.
 */
final class PrimaryColor
{
    public const DEFAULT = '#E8A817';

    /** Dunkelster Seitenhintergrund (#EEF2F7), gegen den Text noch lesbar sein muss. */
    private const PAGE_BACKGROUND_RGB = [238, 242, 247];

    private const MIN_TEXT_CONTRAST = 4.5;

    public static function normalize(mixed $value): string
    {
        $candidate = strtoupper(trim(InputValidator::asString($value)));

        if ($candidate === '') {
            return self::DEFAULT;
        }

        if ($candidate[0] !== '#') {
            $candidate = '#' . $candidate;
        }

        if (preg_match('/^#([A-F0-9]{6}|[A-F0-9]{3})$/', $candidate) !== 1) {
            return self::DEFAULT;
        }

        if (strlen($candidate) === 4) {
            return sprintf(
                '#%1$s%1$s%2$s%2$s%3$s%3$s',
                $candidate[1],
                $candidate[2],
                $candidate[3]
            );
        }

        return $candidate;
    }

    public static function toRgb(string $hexColor): string
    {
        $normalized = ltrim(self::normalize($hexColor), '#');

        $red = hexdec(substr($normalized, 0, 2));
        $green = hexdec(substr($normalized, 2, 2));
        $blue = hexdec(substr($normalized, 4, 2));

        return sprintf('%d, %d, %d', $red, $green, $blue);
    }

    /**
     * Die dunkle Variante der Vereinsfarbe für Text auf hellem Grund (Links, Outline-Knöpfe).
     *
     * Dunkelt schrittweise ab, bis der Kontrast zum Seitenhintergrund mindestens 4,5:1
     * beträgt (WCAG 1.4.3). Ein fester Abdunkelungsfaktor reicht dafür nicht: Das Standardgelb
     * erreichte damit nur 2,9:1, hellere Vereinsfarben noch weniger. Farbton und Sättigung
     * bleiben erhalten, nur die Helligkeit sinkt; eine schon lesbare Farbe bleibt unverändert.
     */
    public static function strong(string $hexColor): string
    {
        $normalized = ltrim(self::normalize($hexColor), '#');
        $channels = [
            (int) hexdec(substr($normalized, 0, 2)),
            (int) hexdec(substr($normalized, 2, 2)),
            (int) hexdec(substr($normalized, 4, 2)),
        ];

        for ($factor = 1.0; $factor > 0.0; $factor -= 0.01) {
            $candidate = array_map(
                static fn (int $channel): int => (int) round($channel * $factor),
                $channels
            );

            if (self::contrastRatio($candidate, self::PAGE_BACKGROUND_RGB) >= self::MIN_TEXT_CONTRAST) {
                return sprintf('#%02X%02X%02X', ...$candidate);
            }
        }

        return '#000000';
    }

    /**
     * @param array{int, int, int} $first
     * @param array{int, int, int} $second
     */
    private static function contrastRatio(array $first, array $second): float
    {
        $firstLuminance = self::relativeLuminance($first);
        $secondLuminance = self::relativeLuminance($second);

        return (max($firstLuminance, $secondLuminance) + 0.05) / (min($firstLuminance, $secondLuminance) + 0.05);
    }

    /**
     * @param array{int, int, int} $rgb
     */
    private static function relativeLuminance(array $rgb): float
    {
        [$red, $green, $blue] = array_map(
            static function (int $channel): float {
                $unit = $channel / 255;

                return $unit <= 0.03928 ? $unit / 12.92 : (($unit + 0.055) / 1.055) ** 2.4;
            },
            $rgb
        );

        return 0.2126 * $red + 0.7152 * $green + 0.0722 * $blue;
    }
}
