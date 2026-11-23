<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\BudgetCategory;
use App\Models\Finance;
use App\Models\FinanceGroup;
use Carbon\Carbon;
use Illuminate\Database\Eloquent\Builder;

/**
 * Höchstens eine budgetrelevante Gruppe je Buchung.
 *
 * Budgetrelevant ist eine Gruppe in einem Geschäftsjahr, wenn es dort eine
 * Budget-Kategorie mit ihr gibt - gleich ob für Einnahmen oder Ausgaben, damit
 * die Regel erklärbar bleibt. Trüge eine Buchung zwei solche Gruppen, zählte sie
 * im Budget-Ist zweier Kategorien. Ein Anlass kann dabei kurzlebig selbst
 * Kostenart sein: Im Jahr seiner Kategorie ist er budgetrelevant, in anderen
 * Jahren nicht.
 */
class FinanceGroupBudgetRule
{
    public function __construct(private readonly BudgetService $budget)
    {
    }

    /**
     * Startjahr des Geschäftsjahres einer Buchung: nach dem Zahldatum, bei
     * offenen Posten nach dem Rechnungsdatum.
     */
    public function fiscalYearOf(?string $paymentDate, string $invoiceDate): int
    {
        $date = Carbon::parse($paymentDate !== null && trim($paymentDate) !== '' ? $paymentDate : $invoiceDate);
        [$day, $month] = $this->budget->getFiscalConfig();

        $startsThisYear = $date->month > $month || ($date->month === $month && $date->day >= $day);

        return $startsThisYear ? (int) $date->year : (int) $date->year - 1;
    }

    /**
     * Meldung, wenn unter den Gruppen mehr als eine im Geschäftsjahr budgetiert
     * ist; sonst `null`. Genannt werden die ersten beiden, alphabetisch.
     *
     * `$budgetedIds`: schon gelesene budgetierte Gruppen des Jahres, damit eine
     * Schleife über viele Buchungen sie nicht je Buchung neu abfragt.
     *
     * @param list<int> $groupIds
     * @param list<int>|null $budgetedIds
     */
    public function conflictMessage(array $groupIds, int $fiscalYear, ?array $budgetedIds = null): ?string
    {
        $budgetedIds ??= $this->budgetedGroupIds($fiscalYear);
        $budgeted = array_values(array_intersect(array_unique($groupIds), $budgetedIds));
        if (count($budgeted) < 2) {
            return null;
        }

        $names = FinanceGroup::whereKey($budgeted)->pluck('name')
            ->map(static fn($name): string => (string) $name)
            ->all();
        sort($names, SORT_NATURAL | SORT_FLAG_CASE);

        return sprintf(
            'Die Gruppen „%s“ und „%s“ sind im Geschäftsjahr %s beide im Budget. '
                . 'Eine Buchung darf nur zu einer davon gehören.',
            $names[0],
            $names[1],
            $this->yearLabel($fiscalYear)
        );
    }

    /**
     * Buchungen des Geschäftsjahres, die `$groupId` und zugleich eine andere dort
     * budgetierte Gruppe tragen. Storno-Paare bleiben außen vor: Sie zählen im
     * Budget-Ist nicht und können deshalb nicht doppelt zählen.
     *
     * `$ignoreCategoryId`: die Kategorie, die gerade auf `$groupId` umgehängt
     * wird - ihre bisherige Gruppe ist danach nicht mehr budgetiert.
     *
     * @return list<int>
     */
    public function conflictingBookingIds(int $groupId, int $fiscalYear, ?int $ignoreCategoryId = null): array
    {
        $others = array_values(array_diff($this->budgetedGroupIds($fiscalYear, $ignoreCategoryId), [$groupId]));
        if ($others === []) {
            return [];
        }

        [$day, $month] = $this->budget->getFiscalConfig();
        [$from, $to] = $this->budget->datesForYear($fiscalYear, $day, $month);
        $range = [$from->format('Y-m-d'), $to->format('Y-m-d')];

        return Finance::query()
            ->whereHas('groups', static fn(Builder $query) => $query->whereKey($groupId))
            ->whereHas('groups', static fn(Builder $query) => $query->whereKey($others))
            ->whereNull('reversal_of_id')
            ->whereDoesntHave('reversedBy')
            ->where(static function (Builder $query) use ($range): void {
                $query->whereBetween('payment_date', $range)
                    ->orWhere(static function (Builder $open) use ($range): void {
                        $open->whereNull('payment_date')->whereBetween('invoice_date', $range);
                    });
            })
            ->orderBy('running_number')
            ->pluck('id')
            ->map(static fn($id): int => (int) $id)
            ->all();
    }

    /**
     * Ohne Zwischenspeicher: Der Dienst lebt so lange wie der Container, und eine
     * dazwischen angelegte Budget-Kategorie muss sofort zählen.
     *
     * @return list<int>
     */
    public function budgetedGroupIds(int $fiscalYear, ?int $ignoreCategoryId = null): array
    {
        return BudgetCategory::where('fiscal_year_start', $fiscalYear)
            ->when($ignoreCategoryId !== null, static fn($query) => $query->whereKeyNot($ignoreCategoryId))
            ->pluck('finance_group_id')
            ->map(static fn($id): int => (int) $id)
            ->unique()
            ->values()
            ->all();
    }

    /**
     * "2026" bei einem Geschäftsjahr ab 1. Jänner, sonst "2026/27".
     */
    public function yearLabel(int $fiscalYear): string
    {
        [$day, $month] = $this->budget->getFiscalConfig();
        if ($day === 1 && $month === 1) {
            return (string) $fiscalYear;
        }

        return $fiscalYear . '/' . substr((string) ($fiscalYear + 1), 2);
    }
}
