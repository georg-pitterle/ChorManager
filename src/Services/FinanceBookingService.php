<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\Finance;
use App\Models\FinanceAccount;
use App\Models\FinanceGroup;
use App\Models\Setting;
use Illuminate\Database\Capsule\Manager as Capsule;

/**
 * Schreibt Buchungen: Anlegen und Ändern, Kontoauszug-Import und Storno.
 *
 * Die Transaktionen standen im FinanceController. Jede Methode hält Periodensperre,
 * Nummernkreis und Journaleintrag in derselben Transaktion zusammen; die
 * Antworten an den Aufrufer ersetzen die Verzweigungen über Referenzparameter.
 */
class FinanceBookingService
{
    public const BULK_ADD = 'add';
    public const BULK_REMOVE = 'remove';

    private readonly FinanceGroupBudgetRule $budgetRule;

    public function __construct(
        private readonly FinanceJournalService $journal,
        ?FinanceGroupBudgetRule $budgetRule = null
    ) {
        $this->budgetRule = $budgetRule ?? new FinanceGroupBudgetRule(new BudgetService());
    }

    /**
     * Atomically reserves a contiguous block of running numbers in a single locked
     * round trip and returns the first number of that block. The counter never
     * decreases, so a running number is never reused even after the highest booking
     * is deleted; it falls back to the current table max in case the counter is
     * behind (e.g. after dev-seed data was inserted directly). Bulk imports would
     * otherwise have to lock the counter row once per booking.
     */
    private function reserveRunningNumbers(int $count): int
    {
        $count = max(1, $count);

        Capsule::connection()->statement(
            "INSERT IGNORE INTO settings (setting_key, setting_value) VALUES (?, '0')",
            ['finance_next_running_number']
        );

        $counterRow = Setting::where('setting_key', 'finance_next_running_number')->lockForUpdate()->first();
        $counterNext = ((int) $counterRow->setting_value) + 1;
        $tableNext = ((int) Finance::max('running_number')) + 1;
        $first = max($counterNext, $tableNext);

        Setting::where('setting_key', 'finance_next_running_number')
            ->update(['setting_value' => (string) ($first + $count - 1)]);

        return $first;
    }

    /**
     * Legt eine Buchung an ($id === null) oder ändert sie.
     *
     * `null` heißt: Der Zeitraum ist gesperrt, nichts wurde geschrieben.
     *
     * @throws FinanceGroupConflictException wenn zwei Gruppen im Geschäftsjahr budgetiert sind
     * @param array<string, mixed> $recordData
     * @param list<string> $groupNames
     */
    public function save(?int $id, array $recordData, array $groupNames, ?int $actorId): ?Finance
    {
        $finance = null;
        $locked = false;

        Capsule::connection()->transaction(
            function () use ($id, $recordData, $groupNames, $actorId, &$finance, &$locked): void {
                if ($id) {
                    $finance = Finance::findOrFail($id);

                    // Sowohl der bisherige als auch der neue Zeitraum müssen offen
                    // sein - sonst ließe sich eine Buchung aus einem geprüften Jahr
                    // heraus- oder hineinschieben.
                    if (
                        $this->journal->isFinanceLocked($finance)
                        || $this->journal->isLocked($recordData['payment_date'])
                    ) {
                        $locked = true;
                        return;
                    }

                    // Gruppen erst nach der Sperrprüfung anlegen: Die Sperre kehrt
                    // ohne Ausnahme zurück, die Transaktion würde eine neue Gruppe
                    // sonst festschreiben, obwohl nichts gebucht wurde.
                    $groupIds = $this->resolveGroupIds($groupNames);

                    // Geprüft wird nur, was sich ändert: Wer eine alte Buchung
                    // unverändert speichert, scheitert nicht an einem Konflikt,
                    // der ohne sein Zutun entstanden ist.
                    $currentIds = $finance->groups()->pluck('finance_groups.id')->map('intval')->sort()->values()->all();
                    $sortedIds = $groupIds;
                    sort($sortedIds);
                    $currentYear = $this->budgetRule->fiscalYearOf(
                        $finance->payment_date?->format('Y-m-d'),
                        $finance->invoice_date->format('Y-m-d')
                    );
                    $newYear = $this->fiscalYearOfRecord($recordData);
                    if ($currentIds !== $sortedIds || $currentYear !== $newYear) {
                        $this->assertNoBudgetConflict($groupIds, $newYear);
                    }

                    $before = FinanceJournalService::snapshot($finance);
                    $finance->update($recordData);
                    $this->syncGroups($finance, $groupIds);
                    $after = $recordData + ['groups' => FinanceJournalService::groupsValue($finance->groupNames())];
                    $this->journal->recordUpdate($finance, $before, $after, $actorId);
                } else {
                    if ($this->journal->isLocked($recordData['payment_date'])) {
                        $locked = true;
                        return;
                    }

                    $groupIds = $this->resolveGroupIds($groupNames);
                    $this->assertNoBudgetConflict($groupIds, $this->fiscalYearOfRecord($recordData));

                    $recordData['running_number'] = $this->reserveRunningNumbers(1);
                    $finance = Finance::create($recordData);
                    $this->syncGroups($finance, $groupIds);
                    $this->journal->recordCreate($finance, $actorId);
                }
            }
        );

        return $locked ? null : $finance;
    }

    /**
     * Legt die geprüften Zeilen eines Kontoauszugs als Buchungen an.
     *
     * @param list<array<string, mixed>> $rows geprüfte Zeilen samt group_names und import_hash
     * @return int Zahl der angelegten Buchungen
     */
    public function importRows(array $rows, FinanceAccount $account, ?int $actorId): int
    {
        $imported = 0;

        Capsule::connection()->transaction(function () use ($rows, $account, $actorId, &$imported): void {
            // Erst alle Zeilen prüfen, dann schreiben: Eine kollidierende Zeile
            // weist den ganzen Auszug ab, und die Transaktion nimmt auch die
            // dabei neu angelegten Gruppen zurück. Still übersprungen fiele eine
            // fehlende Buchung aus dem Kontoauszug niemandem auf.
            $resolved = [];
            $rowMessages = [];
            $budgetedByYear = [];
            foreach ($rows as $position => $row) {
                try {
                    $groupIds = $this->resolveGroupIds($row['group_names'] ?? []);
                } catch (FinanceGroupConflictException $e) {
                    $rowMessages[(int) ($row['index'] ?? $position)] = $e->getMessage();
                    continue;
                }
                $resolved[$position] = $groupIds;
                $year = $this->fiscalYearOfRecord($row);
                $budgetedByYear[$year] ??= $this->budgetRule->budgetedGroupIds($year);
                $message = $this->budgetRule->conflictMessage($groupIds, $year, $budgetedByYear[$year]);
                if ($message !== null) {
                    $rowMessages[(int) ($row['index'] ?? $position)] = $message;
                }
            }
            if ($rowMessages !== []) {
                throw new FinanceGroupConflictException((string) reset($rowMessages), $rowMessages);
            }

            $runningNumber = $this->reserveRunningNumbers(count($rows));
            foreach ($rows as $position => $row) {
                $finance = Finance::create([
                    'running_number' => $runningNumber,
                    'invoice_date' => $row['invoice_date'],
                    'payment_date' => $row['payment_date'],
                    'description' => $row['description'],
                    'type' => $row['type'],
                    'amount' => $row['amount'],
                    'finance_account_id' => $account->id,
                    'payment_method' => $account->paymentMethod(),
                    'import_hash' => $row['import_hash'],
                ]);
                // Ohne Zuordnung fehlten die importierten Buchungen im Budget-Ist.
                $this->syncGroups($finance, $resolved[$position]);
                // Auch importierte Buchungen brauchen einen Journaleintrag,
                // sonst hat die Prüfspur genau dort eine Lücke.
                $this->journal->recordCreate($finance, $actorId);
                $runningNumber++;
                $imported++;
            }
        });

        return $imported;
    }

    /**
     * Storniert eine Buchung durch eine Gegenbuchung.
     *
     * `null` heißt: Die Buchung ist bereits storniert, nichts wurde geschrieben.
     */
    public function reverse(Finance $original, ?int $actorId): ?Finance
    {
        $reversal = null;

        Capsule::connection()->transaction(function () use ($original, $actorId, &$reversal): void {
            // Die Prüfung gehört in die Transaktion: zwei gleichzeitige Storno-
            // Requests würden sonst beide durchkommen. Der UNIQUE-Index auf
            // reversal_of_id sichert den Rest ab.
            if (Finance::where('reversal_of_id', $original->id)->lockForUpdate()->exists()) {
                return;
            }

            $originalPaymentDate = $original->payment_date?->format('Y-m-d');

            // Ein abgeschlossener Zeitraum darf sich nicht mehr verändern; das
            // Storno wandert dann auf den ersten wieder offenen Tag. Sonst wird
            // es in der Periode des Originals gebucht. "Heute" reicht dafür
            // nicht: steht der Abschluss-Stichtag in der Zukunft, ist heute
            // selbst noch gesperrt.
            $paymentDate = $originalPaymentDate;
            if ($paymentDate !== null && $this->journal->isLocked($paymentDate)) {
                $paymentDate = $this->journal->firstOpenBookingDay();
            }

            $reversal = Finance::create([
                'running_number' => $this->reserveRunningNumbers(1),
                'invoice_date' => $original->invoice_date->format('Y-m-d'),
                'payment_date' => $paymentDate,
                'description' => sprintf(
                    'Storno zu Nr. %d: %s',
                    $original->running_number,
                    $original->description
                ),
                'type' => $original->type === Finance::TYPE_INCOME ? Finance::TYPE_EXPENSE : Finance::TYPE_INCOME,
                'amount' => $original->amount,
                'payment_method' => $original->payment_method,
                'finance_account_id' => $original->finance_account_id,
                'reversal_of_id' => $original->id,
            ]);

            // Die Gegenbuchung trägt dieselben Gruppen, sonst hebt sie das Original
            // im Bericht nicht in denselben Gruppen auf.
            $this->syncGroups($reversal, $original->groups()->pluck('finance_groups.id')->map('intval')->all());

            $this->journal->recordReverse($reversal, $original, $actorId);
        });

        return $reversal;
    }

    /**
     * Fügt den Buchungen eine Gruppe hinzu oder nimmt sie weg; ihre übrigen
     * Gruppen bleiben stehen.
     *
     * Übersprungen werden Buchungen im abgeschlossenen Zeitraum, stornierte
     * Buchungen und Gegenbuchungen (das Paar soll dieselben Gruppen behalten) und
     * Buchungen, die damit eine zweite Budget-Gruppe im Geschäftsjahr bekämen.
     * Alles in einer Transaktion; jede geänderte Buchung bekommt ihren
     * Journaleintrag.
     *
     * @param list<int> $financeIds
     */
    public function bulkChangeGroup(array $financeIds, string $groupName, string $mode, ?int $actorId): FinanceBulkGroupResult
    {
        if (!in_array($mode, [self::BULK_ADD, self::BULK_REMOVE], true)) {
            throw new \InvalidArgumentException('Unbekannte Stapelaktion: ' . $mode);
        }

        return Capsule::connection()->transaction(
            function () use ($financeIds, $groupName, $mode, $actorId): FinanceBulkGroupResult {
                // Entfernen legt keine Gruppe an, die es nicht gibt. Hinzufügen legt
                // sie an, nimmt sie aber unten wieder weg, wenn sie keine Buchung
                // erreicht hat - Gruppen lassen sich in der Oberfläche nicht löschen.
                $existingId = FinanceGroup::where('name', trim($groupName))->value('id');
                $groupId = $mode === self::BULK_ADD
                    ? ($this->resolveGroupIds([$groupName])[0] ?? null)
                    : $existingId;
                $groupId = $groupId === null ? null : (int) $groupId;
                $createdHere = $existingId === null && $groupId !== null;

                $changed = 0;
                $unchanged = 0;
                $locked = [];
                $reversal = [];
                $conflict = [];
                $budgetedByYear = [];

                $finances = Finance::with('groups')
                    ->withExists('reversedBy')
                    ->whereKey(array_values(array_unique(array_map('intval', $financeIds))))
                    ->orderBy('running_number')
                    ->lockForUpdate()
                    ->get();

                foreach ($finances as $finance) {
                    $number = (int) $finance->running_number;
                    if ($this->journal->isFinanceLocked($finance)) {
                        $locked[] = $number;
                        continue;
                    }
                    if ($finance->reversal_of_id !== null || $finance->reversed_by_exists) {
                        $reversal[] = $number;
                        continue;
                    }

                    $currentIds = $finance->groups->pluck('id')->map('intval')->all();
                    $newIds = match (true) {
                        $groupId === null => $currentIds,
                        $mode === self::BULK_ADD => array_values(array_unique([...$currentIds, $groupId])),
                        default => array_values(array_diff($currentIds, [$groupId])),
                    };
                    if (count($newIds) === count($currentIds)) {
                        $unchanged++;
                        continue;
                    }

                    $year = $this->budgetRule->fiscalYearOf(
                        $finance->payment_date?->format('Y-m-d'),
                        $finance->invoice_date->format('Y-m-d')
                    );
                    $budgetedByYear[$year] ??= $this->budgetRule->budgetedGroupIds($year);
                    $conflicting = $mode === self::BULK_ADD
                        && $this->budgetRule->conflictMessage($newIds, $year, $budgetedByYear[$year]) !== null;
                    if ($conflicting) {
                        $conflict[] = $number;
                        continue;
                    }

                    $before = FinanceJournalService::snapshot($finance);
                    $this->syncGroups($finance, $newIds);
                    $this->journal->recordUpdate(
                        $finance,
                        $before,
                        ['groups' => FinanceJournalService::groupsValue($finance->groupNames())],
                        $actorId
                    );
                    $changed++;
                }

                if ($createdHere && $changed === 0) {
                    FinanceGroup::whereKey($groupId)->delete();
                }

                return new FinanceBulkGroupResult($changed, $unchanged, $locked, $reversal, $conflict);
            }
        );
    }

    /**
     * Gruppennamen zu IDs; unbekannte Gruppen werden angelegt.
     *
     * Getrimmt und ohne Rücksicht auf Groß-/Kleinschreibung zusammengefasst -
     * wie die Spalte `finance_groups.name` (utf8mb4_general_ci) es ohnehin tut.
     * Sonst träfe "Konzert" und " konzert" zweimal dieselbe Gruppe, und die
     * zweite Zuordnung scheiterte am Primärschlüssel.
     *
     * @param list<string> $names
     * @return list<int>
     */
    private function resolveGroupIds(array $names): array
    {
        $unique = [];
        foreach ($names as $name) {
            $trimmed = trim((string) $name);
            if ($trimmed !== '') {
                self::assertValidGroupName($trimmed);
                $unique[mb_strtolower($trimmed)] ??= $trimmed;
            }
        }

        $ids = [];
        foreach ($unique as $name) {
            $ids[] = (int) FinanceGroup::firstOrCreate(['name' => $name])->id;
        }

        return array_values(array_unique($ids));
    }

    /**
     * Journal und CSV-Export trennen mehrere Gruppen mit "; ". Ein Name mit
     * Semikolon wäre dort nicht von zwei Gruppen zu unterscheiden.
     *
     * @throws FinanceGroupConflictException
     */
    public static function assertValidGroupName(string $name): void
    {
        if (str_contains($name, ';')) {
            throw new FinanceGroupConflictException(sprintf(
                'Ein Gruppenname darf kein Semikolon enthalten („%s“).',
                $name
            ));
        }
    }

    /**
     * @param array<string, mixed> $record Felder `payment_date` und `invoice_date`
     */
    private function fiscalYearOfRecord(array $record): int
    {
        $paymentDate = $record['payment_date'] ?? null;

        return $this->budgetRule->fiscalYearOf(
            is_string($paymentDate) ? $paymentDate : null,
            (string) $record['invoice_date']
        );
    }

    /**
     * @param list<int> $groupIds
     */
    private function assertNoBudgetConflict(array $groupIds, int $fiscalYear): void
    {
        $message = $this->budgetRule->conflictMessage($groupIds, $fiscalYear);
        if ($message !== null) {
            throw new FinanceGroupConflictException($message);
        }
    }

    /**
     * @param list<int> $groupIds
     */
    private function syncGroups(Finance $finance, array $groupIds): void
    {
        $finance->groups()->sync($groupIds);
        $finance->unsetRelation('groups');
    }
}
