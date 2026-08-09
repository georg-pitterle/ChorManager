<?php

declare(strict_types=1);

namespace App\Services\Pdf;

final class CarryTotals
{
    public function __construct(
        public readonly float $income = 0.0,
        public readonly float $expense = 0.0,
    ) {
    }

    public function balance(): float
    {
        return $this->income - $this->expense;
    }

    public function add(float $income, float $expense): self
    {
        return new self($this->income + $income, $this->expense + $expense);
    }
}
