<?php

declare(strict_types=1);

namespace App\Services;

use Carbon\CarbonImmutable;

/**
 * Berechnet die Termine einer Serie.
 *
 * Bewusst als eigener Dienst und nicht in der Erzeugungsschleife des Controllers:
 * Die Regel hat zwei Fallstricke, die sich nur einzeln prüfen lassen.
 *
 *  - Monats- und Jahrestakt hängen an einem Anker (Monatstag bzw. Monat/Tag des
 *    ersten Termins). Ein fortlaufendes "+1 Monat" auf dem zuletzt erzeugten
 *    Datum schiebt den 31. Jänner in den 3. März und die ganze Reihe hinterher.
 *    Gerechnet wird deshalb immer vom Anker aus; existiert der Tag im Zielmonat
 *    nicht, gilt der Monatsletzte.
 *  - Wochentage beschreiben den wöchentlichen Takt. Für täglich, monatlich und
 *    jährlich haben sie keine Bedeutung und werden nicht ausgewertet - sonst
 *    entstünde aus einer Angabe im Formular stillschweigend eine andere Reihe.
 */
class EventRecurrenceService
{
    /** Obergrenze der erzeugten Termine, damit eine Serie kein Jahrzehnt füllt. */
    public const MAX_OCCURRENCES = 500;

    public const FREQUENCY_DAILY = 'daily';
    public const FREQUENCY_WEEKLY = 'weekly';
    public const FREQUENCY_MONTHLY = 'monthly';
    public const FREQUENCY_YEARLY = 'yearly';

    /**
     * Termine der Serie, aufsteigend, jeweils auf Tagesbasis.
     *
     * @param list<int> $weekdays 1 = Montag bis 7 = Sonntag; nur im Wochentakt wirksam
     * @return list<CarbonImmutable>
     */
    public function occurrences(
        CarbonImmutable $start,
        string $frequency,
        int $interval,
        array $weekdays,
        CarbonImmutable $end
    ): array {
        $interval = max(1, $interval);
        $start = $start->startOfDay();
        $end = $end->endOfDay();

        if ($start->greaterThan($end)) {
            return [];
        }

        return match ($frequency) {
            self::FREQUENCY_DAILY => $this->daily($start, $interval, $end),
            self::FREQUENCY_MONTHLY => $this->monthly($start, $interval, $end),
            self::FREQUENCY_YEARLY => $this->yearly($start, $interval, $end),
            default => $this->weekly($start, $interval, $weekdays, $end),
        };
    }

    /**
     * @return list<CarbonImmutable>
     */
    private function daily(CarbonImmutable $start, int $interval, CarbonImmutable $end): array
    {
        $dates = [];
        for ($step = 0; count($dates) < self::MAX_OCCURRENCES; $step++) {
            $date = $start->addDays($step * $interval);
            if ($date->greaterThan($end)) {
                break;
            }

            $dates[] = $date;
        }

        return $dates;
    }

    /**
     * Ein Wochenblock umfasst alle gewählten Wochentage derselben Woche; danach
     * werden $interval Wochen weitergesprungen. Ohne Auswahl gilt der Wochentag
     * des ersten Termins.
     *
     * @param list<int> $weekdays
     * @return list<CarbonImmutable>
     */
    private function weekly(CarbonImmutable $start, int $interval, array $weekdays, CarbonImmutable $end): array
    {
        $selected = $this->normalizeWeekdays($weekdays);
        if ($selected === []) {
            $selected = [(int) $start->format('N')];
        }

        $blockStart = $start->startOfWeek(CarbonImmutable::MONDAY);
        $dates = [];

        while (count($dates) < self::MAX_OCCURRENCES) {
            $blockExceedsEnd = true;

            foreach ($selected as $weekday) {
                $date = $blockStart->addDays($weekday - 1);
                if ($date->lessThan($start)) {
                    // Tage vor dem ersten Termin gehören noch nicht zur Serie.
                    $blockExceedsEnd = false;
                    continue;
                }
                if ($date->greaterThan($end)) {
                    continue;
                }

                $blockExceedsEnd = false;
                $dates[] = $date;

                if (count($dates) >= self::MAX_OCCURRENCES) {
                    break;
                }
            }

            if ($blockExceedsEnd) {
                break;
            }

            $blockStart = $blockStart->addWeeks($interval);
            if ($blockStart->greaterThan($end)) {
                break;
            }
        }

        return $dates;
    }

    /**
     * @return list<CarbonImmutable>
     */
    private function monthly(CarbonImmutable $start, int $interval, CarbonImmutable $end): array
    {
        $anchorDay = (int) $start->format('j');
        $dates = [];

        for ($step = 0; count($dates) < self::MAX_OCCURRENCES; $step++) {
            $month = $start->startOfMonth()->addMonths($step * $interval);
            $date = $month->setDay(min($anchorDay, $month->daysInMonth))
                ->setTimeFrom($start);

            if ($date->greaterThan($end)) {
                break;
            }

            $dates[] = $date;
        }

        return $dates;
    }

    /**
     * @return list<CarbonImmutable>
     */
    private function yearly(CarbonImmutable $start, int $interval, CarbonImmutable $end): array
    {
        $anchorMonth = (int) $start->format('n');
        $anchorDay = (int) $start->format('j');
        $dates = [];

        for ($step = 0; count($dates) < self::MAX_OCCURRENCES; $step++) {
            $month = $start->startOfMonth()
                ->addYears($step * $interval)
                ->setMonth($anchorMonth)
                ->startOfMonth();
            // Der 29. Februar fällt in Gemeinjahren auf den 28.
            $date = $month->setDay(min($anchorDay, $month->daysInMonth))
                ->setTimeFrom($start);

            if ($date->greaterThan($end)) {
                break;
            }

            $dates[] = $date;
        }

        return $dates;
    }

    /**
     * @param list<int> $weekdays
     * @return list<int>
     */
    private function normalizeWeekdays(array $weekdays): array
    {
        $normalized = [];
        foreach ($weekdays as $weekday) {
            $day = (int) $weekday;
            if ($day >= 1 && $day <= 7) {
                $normalized[$day] = true;
            }
        }

        $days = array_keys($normalized);
        sort($days);

        return $days;
    }
}
