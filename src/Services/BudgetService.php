<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\BudgetCategory;
use App\Models\Finance;
use App\Models\Setting;
use Carbon\Carbon;

class BudgetService
{
    /**
     * Returns fiscal config: [day, month, settingString]
     *
     * @return array{0: int, 1: int, 2: string}
     */
    public function getFiscalConfig(): array
    {
        $setting = Setting::find('fiscal_year_start');
        $startStr = $setting ? $setting->setting_value : '01.09.';
        $parts = explode('.', $startStr);
        $day = (int) ($parts[0] ?? 1);
        $month = (int) ($parts[1] ?? 9);

        // Guard against malformed settings so date math cannot overflow.
        if ($month < 1 || $month > 12) {
            $month = 9;
        }
        if ($day < 1 || $day > 31) {
            $day = 1;
        }

        return [$day, $month, $startStr];
    }

    /**
     * Returns [Carbon $start, Carbon $end] for the given fiscal year.
     *
     * @return array{0: Carbon, 1: Carbon}
     */
    public function datesForYear(int $startYear, int $day, int $month): array
    {
        // Clamp the day to the actual length of the target month so an invalid
        // fiscal day (e.g. 31 in April, 29.02 in a non-leap year) does not overflow
        // into the following month.
        $startDay = min($day, (int) Carbon::create($startYear, $month, 1)->daysInMonth);
        $endMonthDays = (int) Carbon::create($startYear + 1, $month, 1)->daysInMonth;
        $endDay = min($day, $endMonthDays);

        $start = Carbon::create($startYear, $month, $startDay, 0, 0, 0);
        $end = Carbon::create($startYear + 1, $month, $endDay, 0, 0, 0)->subDay();

        return [$start, $end];
    }

    /**
     * Returns the default fiscal year start based on today's date.
     */
    public function defaultFiscalYearStart(): int
    {
        [$day, $month] = $this->getFiscalConfig();
        $now = Carbon::now();

        return ($now->month > $month || ($now->month === $month && $now->day >= $day))
            ? (int) $now->year
            : (int) $now->year - 1;
    }

    /**
     * Returns all fiscal years for which budget categories exist, plus the current default year.
     * Keys are year ints, values are label strings "DD.MM.YYYY – DD.MM.YYYY".
     *
     * @return array<int, string>
     */
    public function buildAvailableYears(): array
    {
        [$day, $month] = $this->getFiscalConfig();
        $years = [];

        $default = $this->defaultFiscalYearStart();

        // Offer a span around the current year so budgets can be planned ahead
        // (and past years reviewed) without a category needing to exist first.
        $candidateYears = range($default - 2, $default + 3);

        $existingYears = array_map(
            'intval',
            BudgetCategory::select('fiscal_year_start')->distinct()->pluck('fiscal_year_start')->toArray()
        );

        foreach (array_unique(array_merge($candidateYears, $existingYears)) as $year) {
            $year = (int) $year;
            [$start, $end] = $this->datesForYear($year, $day, $month);
            $years[$year] = $start->format('d.m.Y') . ' – ' . $end->format('d.m.Y');
        }

        ksort($years);

        return $years;
    }

    /**
     * Aggregates actual (Ist) amounts from the finances table for a given finance group
     * and type within a fiscal year date range. Matching is by finance_group_id (FK) so
     * the link survives label changes.
     */
    public function computeActual(int $financeGroupId, string $type, Carbon $from, Carbon $to): string
    {
        $sum = Finance::where('finance_group_id', $financeGroupId)
            ->where('type', $type)
            ->whereBetween('invoice_date', [$from->format('Y-m-d'), $to->format('Y-m-d')])
            ->sum('amount');

        return number_format((float) $sum, 2, '.', '');
    }

    /**
     * Returns a structured overview for the given fiscal year.
     *
     * @return array<string, mixed>
     */
    public function getOverview(int $fiscalYearStart): array
    {
        [$day, $month] = $this->getFiscalConfig();
        [$from, $to] = $this->datesForYear($fiscalYearStart, $day, $month);

        $categories = BudgetCategory::with(['items', 'financeGroup'])
            ->where('fiscal_year_start', $fiscalYearStart)
            ->orderBy('type')
            ->get()
            ->sortBy(fn ($category) => $category->financeGroup->name ?? '')
            ->values();

        $result = [
            'income' => [],
            'expense' => [],
            'totals' => [
                'income' => ['planned' => '0.00', 'actual' => '0.00', 'diff' => '0.00'],
                'expense' => ['planned' => '0.00', 'actual' => '0.00', 'diff' => '0.00'],
            ],
        ];

        foreach ($categories as $category) {
            $planned = (string) $category->items->sum(fn ($item) => (float) $item->planned_amount);
            $planned = number_format((float) $planned, 2, '.', '');
            $actual = $this->computeActual((int) $category->finance_group_id, $category->type, $from, $to);
            $diff = number_format((float) $planned - (float) $actual, 2, '.', '');

            $type = $category->type;
            $result[$type][] = [
                'category' => $category,
                'items' => $category->items,
                'planned' => $planned,
                'actual' => $actual,
                'diff' => $diff,
            ];

            $totals = &$result['totals'][$type];
            $totals['planned'] = number_format((float) $totals['planned'] + (float) $planned, 2, '.', '');
            $totals['actual'] = number_format((float) $totals['actual'] + (float) $actual, 2, '.', '');
            $totals['diff'] = number_format((float) $totals['diff'] + (float) $diff, 2, '.', '');
        }

        return $result;
    }
}
