<?php

declare(strict_types=1);

namespace App\Services\Pdf;

final class FinanceReportPage
{
    /** @param FinanceReportRow[] $rows */
    public function __construct(
        public readonly CarryTotals $openingCarry,
        public readonly array $rows,
        public readonly CarryTotals $closingCarry,
        public readonly bool $isFirst,
        public readonly bool $isLast,
    ) {
    }
}
