<?php

declare(strict_types=1);

namespace App\Services;

/**
 * Der Kontoauszug-Import wird abgewiesen, weil mindestens eine Zeile in einen
 * abgeschlossenen Zeitraum fällt. § 131 BAO lässt dort keine neue Buchung mehr
 * zu, auch nicht über den Import.
 *
 * `$rowMessages` nennt je Zeile der Vorschau (Schlüssel = Index der Zeile) den
 * Grund - dieselbe Form wie bei FinanceGroupConflictException, damit die
 * Vorschau beide Abweisungen gleich behandeln kann: nichts importiert, Auszug
 * bleibt in der Sitzung, betroffene Zeilen markiert.
 */
final class FinancePeriodLockedException extends \RuntimeException
{
    /**
     * @param array<int, string> $rowMessages
     */
    public function __construct(string $message, public readonly array $rowMessages = [])
    {
        parent::__construct($message);
    }
}
