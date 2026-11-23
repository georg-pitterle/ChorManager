<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\BudgetCategory;
use App\Models\Finance;
use App\Models\FinanceGroup;
use App\Util\InputValidator;
use Illuminate\Database\Capsule\Manager as Capsule;

/**
 * Schreibt Budget-Kategorien: anlegen und auf eine andere Gruppe umhängen.
 *
 * Fachregel: Eine Kategorie darf keine Buchung doppelt zählen lassen. Tragen
 * Buchungen des Jahres die Gruppe zusammen mit einer anderen budgetierten
 * Gruppe, wird abgewiesen - das Budget entsteht ohnehin vorab, bereinigt wird
 * in der Kasse per Stapelbearbeitung.
 */
class BudgetWriteService
{
    /** So viele laufende Nummern nennt die Meldung, der Rest wird gezählt. */
    private const LISTED_NUMBERS = 10;

    public function __construct(private readonly FinanceGroupBudgetRule $rule)
    {
    }

    /**
     * @throws \DomainException wenn es die Kategorie schon gibt
     * @throws BudgetCategoryConflictException wenn Buchungen doppelt zählten
     */
    public function createCategory(int $fiscalYear, int $financeGroupId, string $type): BudgetCategory
    {
        return Capsule::connection()->transaction(
            function () use ($fiscalYear, $financeGroupId, $type): BudgetCategory {
                $this->lockYear($fiscalYear);

                $exists = BudgetCategory::where('fiscal_year_start', $fiscalYear)
                    ->where('finance_group_id', $financeGroupId)
                    ->where('type', $type)
                    ->exists();
                if ($exists) {
                    throw new \DomainException('Diese Kategorie existiert bereits für das gewählte Haushaltsjahr.');
                }

                $this->assertNoDoubleCounting($financeGroupId, $fiscalYear, null, 'anlegen');

                return BudgetCategory::create([
                    'fiscal_year_start' => $fiscalYear,
                    'finance_group_id' => $financeGroupId,
                    'type' => $type,
                ]);
            }
        );
    }

    /**
     * @throws \DomainException wenn es eine Kategorie mit dieser Gruppe schon gibt
     * @throws BudgetCategoryConflictException wenn Buchungen doppelt zählten
     */
    public function changeCategoryGroup(BudgetCategory $category, int $financeGroupId): void
    {
        Capsule::connection()->transaction(function () use ($category, $financeGroupId): void {
            $this->lockYear((int) $category->fiscal_year_start);

            $duplicate = BudgetCategory::where('fiscal_year_start', $category->fiscal_year_start)
                ->where('finance_group_id', $financeGroupId)
                ->where('type', $category->type)
                ->whereKeyNot($category->id)
                ->exists();
            if ($duplicate) {
                throw new \DomainException('Eine Kategorie mit dieser Gruppe existiert bereits.');
            }

            if ((int) $category->finance_group_id !== $financeGroupId) {
                $this->assertNoDoubleCounting(
                    $financeGroupId,
                    (int) $category->fiscal_year_start,
                    (int) $category->id,
                    'ändern'
                );
            }

            $category->update(['finance_group_id' => $financeGroupId]);
        });
    }

    /**
     * Prüfen und Schreiben in einem Zug: Zwei gleichzeitige Änderungen am Budget
     * desselben Jahres laufen nacheinander, statt beide dieselbe Ausgangslage zu
     * prüfen.
     */
    private function lockYear(int $fiscalYear): void
    {
        BudgetCategory::where('fiscal_year_start', $fiscalYear)->lockForUpdate()->get(['id']);
    }

    /**
     * Gruppe aus dem Formular: ein neuer Name legt die Gruppe an (oder findet
     * sie), sonst muss die gewählte ID existieren. `null`, wenn beides fehlt.
     *
     * @param array<string, mixed> $data
     */
    public function resolveFinanceGroupId(array $data): ?int
    {
        $newName = trim(InputValidator::asString($data['new_group_name'] ?? null));
        if ($newName !== '') {
            // Kein Semikolon: Journal und Export der Kasse trennen Gruppen damit.
            if (str_contains($newName, ';')) {
                return null;
            }

            return (int) FinanceGroup::firstOrCreate(['name' => $newName])->id;
        }

        $selectedId = (int) ($data['finance_group_id'] ?? 0);
        if ($selectedId > 0 && FinanceGroup::whereKey($selectedId)->exists()) {
            return $selectedId;
        }

        return null;
    }

    /**
     * Ist die Gruppe im Jahr schon budgetiert (andere Art), ändert eine weitere
     * Kategorie nichts an der Zählung; sonst dürfen keine Buchungen sie zusammen
     * mit einer anderen budgetierten Gruppe tragen.
     */
    private function assertNoDoubleCounting(int $groupId, int $fiscalYear, ?int $ignoreCategoryId, string $verb): void
    {
        $alreadyBudgeted = BudgetCategory::where('fiscal_year_start', $fiscalYear)
            ->where('finance_group_id', $groupId)
            ->when($ignoreCategoryId !== null, static fn($query) => $query->whereKeyNot($ignoreCategoryId))
            ->exists();
        if ($alreadyBudgeted) {
            return;
        }

        $ids = $this->rule->conflictingBookingIds($groupId, $fiscalYear, $ignoreCategoryId);
        if ($ids === []) {
            return;
        }

        $numbers = Finance::whereKey($ids)->orderBy('running_number')->pluck('running_number')
            ->map(static fn($number): int => (int) $number)
            ->all();
        $listed = implode(', ', array_slice($numbers, 0, self::LISTED_NUMBERS));
        if (count($numbers) > self::LISTED_NUMBERS) {
            $listed .= sprintf(' und %d weitere', count($numbers) - self::LISTED_NUMBERS);
        }

        throw new BudgetCategoryConflictException(sprintf(
            'Die Kategorie lässt sich nicht %s: %s auch zu einer anderen Budget-Gruppe und %s im '
                . 'Geschäftsjahr %s doppelt zählen (Nr. %s). Bitte zuerst in der Kasse eine der beiden '
                . 'Gruppen bei diesen Buchungen entfernen.',
            $verb,
            count($numbers) === 1 ? '1 Buchung gehört' : count($numbers) . ' Buchungen gehören',
            count($numbers) === 1 ? 'würde' : 'würden',
            $this->rule->yearLabel($fiscalYear),
            $listed
        ), $numbers);
    }
}
