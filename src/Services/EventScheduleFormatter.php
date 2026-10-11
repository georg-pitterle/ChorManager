<?php

declare(strict_types=1);

namespace App\Services;

use DateTimeInterface;

/**
 * Beschriftet Termine so, dass ihre Lage in der Zeit ohne Kopfrechnen lesbar
 * ist: Wochentag, "morgen" statt eines bloßen Datums, Monatsgruppen.
 *
 * Die Namen stehen hier fest statt über die Locale des Servers: Ein Container
 * ohne deutsche Locale lieferte sonst stillschweigend "Thu" und "October".
 */
final class EventScheduleFormatter
{
    /** Bis zu diesem Abstand in Tagen nennt die Seite eine relative Angabe. */
    private const RELATIVE_DAYS = 6;

    private const WEEKDAYS = [1 => 'Mo', 2 => 'Di', 3 => 'Mi', 4 => 'Do', 5 => 'Fr', 6 => 'Sa', 7 => 'So'];

    private const MONTHS = [
        1 => 'Januar', 2 => 'Februar', 3 => 'März', 4 => 'April', 5 => 'Mai', 6 => 'Juni',
        7 => 'Juli', 8 => 'August', 9 => 'September', 10 => 'Oktober', 11 => 'November', 12 => 'Dezember',
    ];

    public function weekday(DateTimeInterface $date): string
    {
        return self::WEEKDAYS[(int) $date->format('N')];
    }

    public function monthLabel(DateTimeInterface $date): string
    {
        return self::MONTHS[(int) $date->format('n')] . ' ' . $date->format('Y');
    }

    /**
     * Gezählt wird in Kalendertagen: Eine Probe morgen früh ist "morgen", auch
     * wenn bis dahin weniger als 24 Stunden vergehen.
     */
    public function relativeLabel(DateTimeInterface $start, DateTimeInterface $now): ?string
    {
        $startDay = new \DateTimeImmutable($start->format('Y-m-d'));
        $today = new \DateTimeImmutable($now->format('Y-m-d'));
        $days = (int) $today->diff($startDay)->format('%r%a');

        return match (true) {
            $days === 0 => 'heute',
            $days === 1 => 'morgen',
            $days > 1 && $days <= self::RELATIVE_DAYS => 'in ' . $days . ' Tagen',
            default => null,
        };
    }

    /**
     * Fasst bereits sortierte Termine zu Monaten zusammen; die Reihenfolge
     * innerhalb und zwischen den Monaten bleibt, wie sie kam.
     *
     * @param iterable<object> $events Objekte mit `starts_at` als DateTimeInterface
     * @return list<array{key: string, label: string, events: list<object>}>
     */
    public function groupByMonth(iterable $events): array
    {
        $groups = [];
        foreach ($events as $event) {
            $key = $event->starts_at->format('Y-m');
            if (!isset($groups[$key])) {
                $groups[$key] = [
                    'key' => $key,
                    'label' => $this->monthLabel($event->starts_at),
                    'events' => [],
                ];
            }
            $groups[$key]['events'][] = $event;
        }

        return array_values($groups);
    }
}
