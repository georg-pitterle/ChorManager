<?php

declare(strict_types=1);

namespace App\Util;

/**
 * Einzige Quelle für die Status einer Sponsoring-Vereinbarung.
 *
 * Werte, Labels, Badge-Farben und die Auswertungsgruppen lagen vorher in fünf
 * Kopien nebeneinander (Controller, drei Templates, Tabellen-Plugin) und sind
 * dabei auseinandergelaufen. Wer einen Status ergänzt, ändert nur noch diese
 * Klasse und die Enum-Definition der Spalte `sponsorships.status`.
 */
final class SponsorshipStatus
{
    public const REQUESTED = 'requested';
    public const REMINDED  = 'reminded';
    public const ACCEPTED  = 'accepted';
    public const DECLINED  = 'declined';
    public const CLOSED    = 'closed';

    public const DEFAULT = self::REQUESTED;

    /**
     * Noch laufende Anfragen: sie bilden die Pipeline auf dem Dashboard.
     *
     * @var list<string>
     */
    public const OPEN = [self::REQUESTED, self::REMINDED];

    /** @var array<string, string> */
    private const LABELS = [
        self::REQUESTED => 'Angefragt',
        self::REMINDED  => 'Erinnert',
        self::ACCEPTED  => 'Zusage',
        self::DECLINED  => 'Absage',
        self::CLOSED    => 'Abgeschlossen',
    ];

    /** @var array<string, string> */
    private const COLORS = [
        self::REQUESTED => 'secondary',
        self::REMINDED  => 'warning',
        self::ACCEPTED  => 'success',
        self::DECLINED  => 'danger',
        self::CLOSED    => 'dark',
    ];

    /**
     * @return list<string>
     */
    public static function all(): array
    {
        return array_keys(self::LABELS);
    }

    /**
     * @return array<string, string>
     */
    public static function labels(): array
    {
        return self::LABELS;
    }

    /**
     * @return array<string, string>
     */
    public static function colors(): array
    {
        return self::COLORS;
    }

    public static function isValid(string $status): bool
    {
        return isset(self::LABELS[$status]);
    }

    public static function label(?string $status): string
    {
        return self::LABELS[$status] ?? 'Unbekannt';
    }

    public static function color(?string $status): string
    {
        return self::COLORS[$status] ?? 'secondary';
    }

    /**
     * Auswahlliste für Formulare und Filter - Wert samt Beschriftung, damit
     * Templates und das Tabellen-Plugin dieselbe Reihenfolge zeigen.
     *
     * @return list<array{value: string, label: string, color: string}>
     */
    public static function options(): array
    {
        $options = [];
        foreach (self::LABELS as $value => $label) {
            $options[] = [
                'value' => $value,
                'label' => $label,
                'color' => self::COLORS[$value],
            ];
        }

        return $options;
    }
}
