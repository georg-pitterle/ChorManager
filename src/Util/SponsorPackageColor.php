<?php

declare(strict_types=1);

namespace App\Util;

/**
 * Farben der Sponsoring-Pakete.
 *
 * Gespeichert bleibt der Bootstrap-Name (`warning`, `info` ...), angezeigt wird
 * ein Wort, das ein Vorstand versteht. Die Paketseite zeigte vorher den rohen
 * Namen als Text und im Auswahlfeld, und die Abzeichen setzten `bg-*` ohne
 * Schriftfarbe: Weiß auf Gelb ("Gold") kam auf 1,63:1, Weiß auf Hellblau
 * ("Silber") auf 1,96:1. `text-bg-*` wählt die Schriftfarbe passend zum
 * Hintergrund; für die Vereinsfarbe sorgt style.css.
 *
 * Die Liste ist zugleich die Prüfung beim Speichern: Ein Wert, den das
 * Formular nicht anbietet, ergäbe eine Klasse, die Bootstrap nicht kennt.
 */
final class SponsorPackageColor
{
    public const DEFAULT = 'info';

    /** @var array<string, string> */
    private const LABELS = [
        'warning'   => 'Gold',
        'secondary' => 'Grau',
        'info'      => 'Hellblau',
        'primary'   => 'Vereinsfarbe',
        'success'   => 'Grün',
        'danger'    => 'Rot',
        'dark'      => 'Dunkel',
    ];

    public static function normalize(mixed $value): string
    {
        $color = InputValidator::asString($value);

        return isset(self::LABELS[$color]) ? $color : self::DEFAULT;
    }

    public static function label(?string $color): string
    {
        return self::LABELS[$color ?? ''] ?? self::LABELS[self::DEFAULT];
    }

    public static function badgeClass(?string $color): string
    {
        return 'text-bg-' . (isset(self::LABELS[$color ?? '']) ? $color : self::DEFAULT);
    }

    /**
     * @return list<array{value: string, label: string, badge: string}>
     */
    public static function options(): array
    {
        $options = [];
        foreach (self::LABELS as $value => $label) {
            $options[] = ['value' => $value, 'label' => $label, 'badge' => 'text-bg-' . $value];
        }

        return $options;
    }
}
