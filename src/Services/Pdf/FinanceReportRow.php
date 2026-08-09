<?php

declare(strict_types=1);

namespace App\Services\Pdf;

final class FinanceReportRow
{
    public function __construct(
        public readonly string $date,
        public readonly int $runningNumber,
        public readonly string $description,
        public readonly string $method,
        public readonly float $income,
        public readonly float $expense,
    ) {
    }
}
