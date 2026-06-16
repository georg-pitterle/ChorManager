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

        return [$day, $month, $startStr];
    }

    /**
     * Returns [Carbon $start, Carbon $end] for the given fiscal year.
     *
     * @return array{0: Carbon, 1: Carbon}
     */
    public function datesForYear(int $startYear, int $day, int $month): array
    {
        $start = Carbon::create($startYear, $month, $day, 0, 0, 0);
        $end = Carbon::create($startYear + 1, $month, $day, 0, 0, 0)->subDay();

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
        [$start, $end] = $this->datesForYear($default, $day, $month);
        $years[$default] = $start->format('d.m.Y') . ' – ' . $end->format('d.m.Y');

        $existingYears = BudgetCategory::select('fiscal_year_start')
            ->distinct()
            ->pluck('fiscal_year_start')
            ->toArray();

        foreach ($existingYears as $year) {
            $year = (int) $year;
            if (!isset($years[$year])) {
                [$start, $end] = $this->datesForYear($year, $day, $month);
                $years[$year] = $start->format('d.m.Y') . ' – ' . $end->format('d.m.Y');
            }
        }

        ksort($years);

        return $years;
    }

    /**
     * Aggregates actual (Ist) amounts from the finances table for a given group_name and type
     * within a fiscal year date range.
     */
    public function computeActual(string $groupName, string $type, Carbon $from, Carbon $to): string
    {
        $sum = Finance::where('group_name', $groupName)
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

        $categories = BudgetCategory::with('items')
            ->where('fiscal_year_start', $fiscalYearStart)
            ->orderBy('type')
            ->orderBy('group_name')
            ->get();

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
            $actual = $this->computeActual($category->group_name, $category->type, $from, $to);
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
