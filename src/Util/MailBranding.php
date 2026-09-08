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
     * Rahmen, in den das Logo im Mailkopf hineinpasst. Die Höhe entspricht den bisherigen
     * 56 Pixeln, damit das quadratische Standardlogo unverändert bleibt. Die Breite deckelt
     * ein Banner: die Mailkarte ist 600 Pixel breit, abzüglich zweimal 40 Pixel Innenabstand
     * bleiben 520 — 200 lässt dem Kopfbereich Luft, statt ihn vom Logo füllen zu lassen.
     */
    private const LOGO_BOX_WIDTH = 200;
    private const LOGO_BOX_HEIGHT = 56;

    /**
     * @return array{
     *     app_name: string,
     *     primary_color: string,
     *     primary_strong: string,
     *     primary_tint: string,
     *     primary_edge: string,
     *     logo_src: string,
     *     logo_width: int,
     *     logo_height: int
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

        $logo = self::resolveLogoBinary();
        [$logoWidth, $logoHeight] = self::fitLogoBox($logo['content']);

        return [
            'app_name' => $appName,
            'primary_color' => $primaryColor,
            'primary_strong' => self::readableOnWhite($primaryColor),
            'primary_tint' => self::overWhite($primaryColor, self::TINT_ALPHA),
            'primary_edge' => self::overWhite($primaryColor, self::EDGE_ALPHA),
            'logo_src' => $logo['content'] === ''
                ? ''
                : 'data:' . $logo['mime'] . ';base64,' . base64_encode($logo['content']),
            'logo_width' => $logoWidth,
            'logo_height' => $logoHeight,
        ];
    }

    /**
     * Skaliert das Logo in den Kopfbereich, ohne das Seitenverhältnis zu verändern.
     *
     * Vorher standen im Kopf feste 56x56 Pixel. Das mitgelieferte Logo ist quadratisch und
     * sah damit richtig aus — ein Wappen im Hochformat oder ein Schriftzug im Querformat wurde
     * in dieses Quadrat gequetscht. Da E-Mail-Programme sich auf `height:auto` nicht verlassen
     * lassen (Outlook rechnet mit den Attributen, nicht mit dem Stil), werden beide Maße hier
     * ausgerechnet und fest in die Vorlage geschrieben.
     *
     * Lässt sich das Bild nicht vermessen, bleibt es beim Quadrat: dann ist die Datei ohnehin
     * kaputt und wird in keinem Programm angezeigt.
     *
     * @return array{0: int, 1: int} Breite und Höhe in Pixeln
     */
    public static function fitLogoBox(string $binaryContent): array
    {
        [$width, $height] = ImageBox::fit($binaryContent, self::LOGO_BOX_WIDTH, self::LOGO_BOX_HEIGHT);

        // Ganze Pixel: gebrochene Werte im HTML-Attribut werten Mailprogramme unterschiedlich aus.
        return [max(1, (int) round($width)), max(1, (int) round($height))];
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

    /**
     * Liefert das Logo als Rohdaten, nicht als fertige `data:`-URI: die Maße lassen sich nur
     * am Bild selbst ablesen, und ein Umweg über Base64 und zurück wäre reine Arbeit.
     *
     * @return array{content: string, mime: string}
     */
    private static function resolveLogoBinary(): array
    {
        try {
            $logo = AppSetting::query()->find('app_logo');
            if ($logo instanceof AppSetting && $logo->binary_content !== '') {
                $mimeType = trim((string) $logo->mime_type);

                return [
                    'content' => (string) $logo->binary_content,
                    'mime' => $mimeType === '' ? 'image/png' : $mimeType,
                ];
            }
        } catch (\Throwable) {
            return self::defaultLogoBinary();
        }

        return self::defaultLogoBinary();
    }

    /**
     * @return array{content: string, mime: string}
     */
    private static function defaultLogoBinary(): array
    {
        $defaultLogoPath = __DIR__ . '/../../public/icons/icon-512.png';
        if (!is_file($defaultLogoPath)) {
            return ['content' => '', 'mime' => 'image/png'];
        }

        $binaryContent = @file_get_contents($defaultLogoPath);
        if ($binaryContent === false || $binaryContent === '') {
            return ['content' => '', 'mime' => 'image/png'];
        }

        return ['content' => $binaryContent, 'mime' => 'image/png'];
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
