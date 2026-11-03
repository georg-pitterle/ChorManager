<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\Poll;
use Carbon\Carbon;

/**
 * Fristtexte einer Umfrage, gemeinsam für Übersicht, Detailseite, Startseite
 * und Benachrichtigung. Den Wochentag liefert der Terminformatierer, damit
 * "Fr" überall gleich heißt.
 */
final class PollLabels
{
    public function __construct(
        private readonly EventScheduleFormatter $schedule = new EventScheduleFormatter()
    ) {
    }

    /** "Läuft bis Fr, 13.11." bzw. "Beendet am Fr, 13.11." */
    public function deadline(Poll $poll): string
    {
        return ($poll->isClosed() ? 'Beendet am ' : 'Läuft bis ') . $this->date($poll);
    }

    /** Der Tag der Frist: "Fr, 13.11." */
    public function date(Poll $poll): string
    {
        $closesAt = $poll->closes_at ?? Carbon::now();

        return $this->schedule->weekday($closesAt) . ', ' . $closesAt->format('d.m.');
    }

    /** "noch 3 Tage", "endet morgen", "endet heute". */
    public function remaining(Poll $poll): string
    {
        $today = Carbon::today();
        $days = (int) $today->diffInDays(($poll->closes_at ?? Carbon::now())->copy()->startOfDay(), false);

        return match (true) {
            $days <= 0 => 'endet heute',
            $days === 1 => 'endet morgen',
            default => 'noch ' . $days . ' Tage',
        };
    }

    /**
     * Eine Zeile für die Listen in Übersicht und Startseite
     * (templates/polls/_overview_items.twig).
     *
     * @return array<string, mixed>
     */
    public function overviewRow(Poll $poll, bool $inAudience, bool $hasVoted): array
    {
        $isClosed = $poll->isClosed();

        return [
            'poll' => $poll,
            'closes_at' => $poll->closes_at?->getTimestamp() ?? 0,
            'missing' => !$isClosed && $inAudience && !$hasVoted,
            'has_voted' => $hasVoted,
            'is_closed' => $isClosed,
            'deadline_label' => $this->deadline($poll),
            'relative_label' => $isClosed ? null : $this->remaining($poll),
        ];
    }
}
