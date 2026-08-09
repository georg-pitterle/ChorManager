<?php

declare(strict_types=1);

namespace App\Services\Pdf;

final class FinanceReportPaginator
{
    /**
     * Teilt Bewegungszeilen auf Seiten auf und berechnet je Seite Eröffnungs-
     * und Abschluss-Übertrag (kumuliert). Auf jeder Seite bleibt unten
     * $carryRowHeight für die Übertrag-/Gesamtsaldo-Zeile reserviert.
     *
     * @param array{0: FinanceReportRow, 1: float}[] $rowsWithHeights
     * @return FinanceReportPage[]
     */
    public static function paginate(
        array $rowsWithHeights,
        float $firstPageTop,
        float $otherPageTop,
        float $contentBottom,
        float $carryRowHeight
    ): array {
        $limit = $contentBottom - $carryRowHeight;

        // Sonderfall: keine Bewegungen -> eine leere Seite.
        if ($rowsWithHeights === []) {
            $zero = new CarryTotals();
            return [new FinanceReportPage($zero, [], $zero, true, true)];
        }

        $pages = [];
        $running = new CarryTotals();
        $isFirst = true;
        $cursor = $firstPageTop;
        $opening = $running;
        $currentRows = [];

        foreach ($rowsWithHeights as [$row, $height]) {
            if ($currentRows !== [] && ($cursor + $height) > $limit) {
                // Seite abschließen (Abschluss-Übertrag = aktueller Stand).
                $pages[] = new FinanceReportPage($opening, $currentRows, $running, $isFirst, false);
                $isFirst = false;
                $opening = $running;
                $currentRows = [];
                $cursor = $otherPageTop;
            }

            $currentRows[] = $row;
            $running = $running->add($row->income, $row->expense);
            $cursor += $height;
        }

        // Letzte (nicht leere) Seite abschließen.
        $pages[] = new FinanceReportPage($opening, $currentRows, $running, $isFirst, true);

        return $pages;
    }
}
