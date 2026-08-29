<?php

declare(strict_types=1);

namespace App\Util;

use App\Models\Sponsor;

/**
 * Leitet den Zustand eines Sponsors aus seinen Vereinbarungen ab.
 *
 * Bis 20260829121000 trug jeder Sponsor einen eigenen Status. Der musste
 * getrennt von den Vereinbarungen gepflegt werden und lief regelmäßig
 * auseinander - eine ausgehandelte Vereinbarung machte den Sponsor nicht
 * "aktiv". Festgehalten wird am Sponsor nur noch die Generalabsage
 * (`requests_blocked`); alles andere ergibt sich aus den Vereinbarungen.
 */
final class SponsorEngagementState
{
    public const BLOCKED  = 'blocked';
    public const ACCEPTED = 'accepted';
    public const OPEN     = 'open';
    public const DECLINED = 'declined';
    public const CLOSED   = 'closed';
    public const NONE     = 'none';

    /** @var array<string, string> */
    private const LABELS = [
        self::BLOCKED  => 'Keine Anfragen erwünscht',
        self::ACCEPTED => 'Zusage',
        self::OPEN     => 'Anfrage läuft',
        self::DECLINED => 'Absage',
        self::CLOSED   => 'Abgeschlossen',
        self::NONE     => 'Keine Vereinbarung',
    ];

    /** @var array<string, string> */
    private const COLORS = [
        self::BLOCKED  => 'danger',
        self::ACCEPTED => 'success',
        self::OPEN     => 'warning',
        self::DECLINED => 'secondary',
        self::CLOSED   => 'dark',
        self::NONE     => 'light',
    ];

    /**
     * Reihenfolge der Prüfung: die Generalabsage schlägt alles, danach zählt
     * der jeweils "positivste" Stand, den eine Vereinbarung erreicht hat.
     */
    public static function forSponsor(Sponsor $sponsor): string
    {
        if ((bool) $sponsor->requests_blocked) {
            return self::BLOCKED;
        }

        $statuses = [];
        foreach ($sponsor->sponsorships as $sponsorship) {
            $statuses[] = (string) $sponsorship->status;
        }

        if ($statuses === []) {
            return self::NONE;
        }

        if (in_array(SponsorshipStatus::ACCEPTED, $statuses, true)) {
            return self::ACCEPTED;
        }

        if (array_intersect(SponsorshipStatus::OPEN, $statuses) !== []) {
            return self::OPEN;
        }

        if (in_array(SponsorshipStatus::DECLINED, $statuses, true)) {
            return self::DECLINED;
        }

        return self::CLOSED;
    }

    /**
     * @return list<string>
     */
    public static function all(): array
    {
        return array_keys(self::LABELS);
    }

    public static function isValid(string $state): bool
    {
        return isset(self::LABELS[$state]);
    }

    public static function label(string $state): string
    {
        return self::LABELS[$state] ?? 'Unbekannt';
    }

    public static function color(string $state): string
    {
        return self::COLORS[$state] ?? 'secondary';
    }

    /**
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
