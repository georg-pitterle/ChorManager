<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\Finance;
use Carbon\Carbon;

/**
 * Exportiert das Kassabuch eines Geschäftsjahres als CSV.
 *
 * Format wie beim Import: UTF-8 mit BOM und Semikolon als Trennzeichen, damit
 * Excel die Datei im deutschsprachigen Raum ohne Nachfrage korrekt öffnet.
 * Beträge mit Dezimalkomma, Datumsangaben als TT.MM.JJJJ.
 */
class FinanceCsvExportService
{
    public const DELIMITER = ';';
    private const BOM = "\xEF\xBB\xBF";

    private const HEADER = [
        'Lfd. Nr.',
        'Rechnungsdatum',
        'Zahldatum',
        'Beschreibung',
        'Gruppe',
        'Art',
        'Betrag',
        'Konto',
        'Zahlungsart',
        'Storno zu',
        'Anhänge',
    ];

    /**
     * @param iterable<Finance> $finances
     */
    public function build(iterable $finances): string
    {
        $handle = fopen('php://temp', 'r+');
        if ($handle === false) {
            return self::BOM;
        }

        fputcsv($handle, self::HEADER, self::DELIMITER, '"', '\\');

        foreach ($finances as $finance) {
            fputcsv($handle, $this->row($finance), self::DELIMITER, '"', '\\');
        }

        rewind($handle);
        $csv = (string) stream_get_contents($handle);
        fclose($handle);

        return self::BOM . $csv;
    }

    public function fileName(Carbon $start, Carbon $end): string
    {
        return sprintf('Kassabuch_%s_%s.csv', $start->format('Y-m-d'), $end->format('Y-m-d'));
    }

    /**
     * @return list<string>
     */
    private function row(Finance $finance): array
    {
        return [
            (string) $finance->running_number,
            $finance->invoice_date?->format('d.m.Y') ?? '',
            $finance->payment_date?->format('d.m.Y') ?? '',
            (string) $finance->description,
            (string) ($finance->group_name ?? ''),
            $finance->type === 'income' ? 'Eingang' : 'Ausgang',
            $this->money((string) $finance->amount, $finance->type === 'expense'),
            (string) ($finance->financeAccount->name ?? ''),
            $finance->payment_method === 'cash' ? 'Bar' : 'Überweisung',
            $finance->reversalOf?->running_number === null
                ? ''
                : (string) $finance->reversalOf->running_number,
            (string) $finance->attachments->count(),
        ];
    }

    /**
     * Betrag mit Dezimalkomma; Ausgänge tragen ein Minus, damit sich die Spalte
     * in einer Tabellenkalkulation direkt aufsummieren lässt.
     */
    private function money(string $amount, bool $isExpense): string
    {
        $value = (float) $amount;

        return number_format($isExpense ? -$value : $value, 2, ',', '');
    }
}
