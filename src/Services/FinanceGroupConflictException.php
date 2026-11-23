<?php

declare(strict_types=1);

namespace App\Services;

/**
 * Die Gruppen einer Buchung werden abgewiesen: zwei Gruppen, die im selben
 * Geschäftsjahr im Budget stehen, oder ein unzulässiger Gruppenname.
 *
 * Beim Import nennt `$rowMessages` je Zeile der Vorschau (Schlüssel = Index der
 * Zeile) den Grund; beim Speichern einer einzelnen Buchung bleibt es leer.
 */
final class FinanceGroupConflictException extends \RuntimeException
{
    /**
     * @param array<int, string> $rowMessages
     */
    public function __construct(string $message, public readonly array $rowMessages = [])
    {
        parent::__construct($message);
    }
}
