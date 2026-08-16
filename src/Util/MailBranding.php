<?php

declare(strict_types=1);

namespace App\Util;

use App\Controllers\AppSettingController;
use App\Models\AppSetting;

/**
 * Loest das Erscheinungsbild der Systemmails auf: Name, Markenfarbe, davon abgeleitete Toene
 * und das Logo. Alle Mail-Absender nutzen dieselbe Quelle, damit Einladung, Passwort-Reset und
 * Erinnerung nicht auseinanderlaufen.
 *
 * Die Markenfarbe ist pro Installation konfigurierbar. Abgeleitet werden daraus nur Toene, die
 * ihre Rolle unabhaengig vom konkreten Farbwert erfuellen muessen.
 */
final class MailBranding
{
    public const DEFAULT_APP_NAME = 'Chor-Manager';

    /** Mindestkontrast fuer Fliesstext nach WCAG 2.1 AA. */
    private const MIN_TEXT_CONTRAST = 4.5;

    /** Deckkraft der Markenfarbe ueber Weiss fuer Flaeche und Rand der Hinweisbox. */
    private const TINT_ALPHA = 0.08;
    private const EDGE_ALPHA = 0.30;

    /**
     * @return array{
     *     app_name: string,
     *     primary_color: string,
     *     primary_strong: string,
     *     primary_tint: string,
     *     primary_edge: string,
     *     logo_src: string
     * }
     */
    public static function resolve(): array
    {
        $appName = self::DEFAULT_APP_NAME;
        $primaryColor = AppSettingController::DEFAULT_PRIMARY_COLOR;

        try {
            $settings = AppSetting::query()
                ->whereIn('setting_key', ['app_name', 'primary_color'])
                ->pluck('setting_value', 'setting_key')
                ->toArray();

            $configuredAppName = trim((string) ($settings['app_name'] ?? ''));
            if ($configuredAppName !== '') {
                $appName = $configuredAppName;
            }

            $primaryColor = AppSettingController::normalizePrimaryColor($settings['primary_color'] ?? null);
        } catch (\Throwable) {
            $primaryColor = AppSettingController::DEFAULT_PRIMARY_COLOR;
        }

        return [
            'app_name' => $appName,
            'primary_color' => $primaryColor,
            'primary_strong' => self::readableOnWhite($primaryColor),
            'primary_tint' => self::overWhite($primaryColor, self::TINT_ALPHA),
            'primary_edge' => self::overWhite($primaryColor, self::EDGE_ALPHA),
            'logo_src' => self::resolveLogo(),
        ];
    }

    /**
     * Dunkelt die Markenfarbe so weit ab, bis Text darin auf Weiss AA erfuellt. Eine feste
     * Prozentmischung reicht nicht: helle Markenfarben wie Amber blieben sonst unter 3:1.
     */
    public static function readableOnWhite(string $hexColor): string
    {
        [$red, $green, $blue] = self::toRgb($hexColor);

        for ($step = 0; $step < 100; $step++) {
            if (self::contrastWithWhite($red, $green, $blue) >= self::MIN_TEXT_CONTRAST) {
                break;
            }

            $red = (int) round($red * 0.97);
            $green = (int) round($green * 0.97);
            $blue = (int) round($blue * 0.97);
        }

        return self::toHex($red, $green, $blue);
    }

    /** Mischt die Markenfarbe mit der angegebenen Deckkraft ueber Weiss. */
    public static function overWhite(string $hexColor, float $alpha): string
    {
        [$red, $green, $blue] = self::toRgb($hexColor);

        return self::toHex(
            (int) round(255 - (255 - $red) * $alpha),
            (int) round(255 - (255 - $green) * $alpha),
            (int) round(255 - (255 - $blue) * $alpha)
        );
    }

    private static function resolveLogo(): string
    {
        try {
            $logo = AppSetting::query()->find('app_logo');
            if ($logo instanceof AppSetting && $logo->binary_content !== '') {
                $mimeType = trim((string) $logo->mime_type);
                if ($mimeType === '') {
                    $mimeType = 'image/png';
                }

                return 'data:' . $mimeType . ';base64,' . base64_encode($logo->binary_content);
            }
        } catch (\Throwable) {
            return self::defaultLogoDataUri();
        }

        return self::defaultLogoDataUri();
    }

    private static function defaultLogoDataUri(): string
    {
        $defaultLogoPath = __DIR__ . '/../../public/icons/icon-512.png';
        if (!is_file($defaultLogoPath)) {
            return '';
        }

        $binaryContent = @file_get_contents($defaultLogoPath);
        if ($binaryContent === false || $binaryContent === '') {
            return '';
        }

        return 'data:image/png;base64,' . base64_encode($binaryContent);
    }

    /**
     * @return array{0: int, 1: int, 2: int}
     */
    private static function toRgb(string $hexColor): array
    {
        $hex = ltrim(trim($hexColor), '#');
        if (strlen($hex) === 3) {
            $hex = $hex[0] . $hex[0] . $hex[1] . $hex[1] . $hex[2] . $hex[2];
        }

        if (strlen($hex) !== 6 || !ctype_xdigit($hex)) {
            $hex = ltrim(AppSettingController::DEFAULT_PRIMARY_COLOR, '#');
        }

        return [
            (int) hexdec(substr($hex, 0, 2)),
            (int) hexdec(substr($hex, 2, 2)),
            (int) hexdec(substr($hex, 4, 2)),
        ];
    }

    private static function toHex(int $red, int $green, int $blue): string
    {
        return sprintf(
            '#%02x%02x%02x',
            max(0, min(255, $red)),
            max(0, min(255, $green)),
            max(0, min(255, $blue))
        );
    }

    private static function contrastWithWhite(int $red, int $green, int $blue): float
    {
        $luminance = 0.2126 * self::linearize($red)
            + 0.7152 * self::linearize($green)
            + 0.0722 * self::linearize($blue);

        return 1.05 / ($luminance + 0.05);
    }

    private static function linearize(int $channel): float
    {
        $value = $channel / 255;

        return $value <= 0.03928
            ? $value / 12.92
            : (($value + 0.055) / 1.055) ** 2.4;
    }
}
