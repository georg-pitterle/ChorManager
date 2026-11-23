<?php

declare(strict_types=1);

namespace App\Services;

/**
 * Eine Budget-Kategorie ließe Buchungen doppelt zählen: Sie tragen die Gruppe
 * und zugleich eine andere, im selben Geschäftsjahr budgetierte Gruppe.
 */
final class BudgetCategoryConflictException extends \RuntimeException
{
    /**
     * @param list<int> $runningNumbers laufende Nummern der betroffenen Buchungen
     */
    public function __construct(string $message, public readonly array $runningNumbers)
    {
        parent::__construct($message);
    }
}
