<?php

declare(strict_types=1);

namespace App\Services;

/**
 * Ergebnis einer Stapelbearbeitung der Gruppen. Die übersprungenen Buchungen
 * stehen mit ihren laufenden Nummern da, damit die Meldung sie nennen kann.
 */
final class FinanceBulkGroupResult
{
    /** So viele Nummern nennt die Meldung je Grund, der Rest wird gezählt. */
    private const LISTED_NUMBERS = 10;

    /**
     * @param list<int> $skippedLocked
     * @param list<int> $skippedReversal
     * @param list<int> $skippedBudgetConflict
     */
    public function __construct(
        public readonly int $changed,
        public readonly int $unchanged,
        public readonly array $skippedLocked,
        public readonly array $skippedReversal,
        public readonly array $skippedBudgetConflict
    ) {
    }

    public function message(): string
    {
        $message = sprintf('%s geändert', $this->changed === 1 ? '1 Buchung' : $this->changed . ' Buchungen');
        if ($this->unchanged > 0) {
            $message .= sprintf(', %d %s schon so', $this->unchanged, $this->unchanged === 1 ? 'war' : 'waren');
        }
        $message .= '.';

        $skipped = array_filter([
            $this->part($this->skippedLocked, 'im abgeschlossenen Zeitraum'),
            $this->part($this->skippedReversal, 'storniert'),
            $this->part($this->skippedBudgetConflict, 'mit zweiter Budget-Gruppe'),
        ]);
        if ($skipped !== []) {
            $message .= ' Übersprungen: ' . implode(', ', $skipped) . '.';
        }

        return $message;
    }

    /**
     * @param list<int> $numbers
     */
    private function part(array $numbers, string $reason): ?string
    {
        if ($numbers === []) {
            return null;
        }

        $listed = implode(', ', array_slice($numbers, 0, self::LISTED_NUMBERS));
        $rest = count($numbers) - self::LISTED_NUMBERS;
        if ($rest > 0) {
            $listed .= sprintf(' und %d weitere', $rest);
        }

        return sprintf('%d %s (Nr. %s)', count($numbers), $reason, $listed);
    }
}
