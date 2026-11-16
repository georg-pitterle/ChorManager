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
    public function __construct(private readonly FinanceJournalService $journal)
    {
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
     * @param array<string, mixed> $recordData
     */
    public function save(?int $id, array $recordData, ?int $actorId): ?Finance
    {
        $finance = null;
        $locked = false;

        Capsule::connection()->transaction(
            function () use ($id, $recordData, $actorId, &$finance, &$locked): void {
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

                    $before = FinanceJournalService::snapshot($finance);
                    $finance->update($recordData);
                    $this->journal->recordUpdate($finance, $before, $recordData, $actorId);
                } else {
                    if ($this->journal->isLocked($recordData['payment_date'])) {
                        $locked = true;
                        return;
                    }

                    $recordData['running_number'] = $this->reserveRunningNumbers(1);
                    $finance = Finance::create($recordData);
                    $this->journal->recordCreate($finance, $actorId);
                }
            }
        );

        return $locked ? null : $finance;
    }

    /**
     * Legt die geprüften Zeilen eines Kontoauszugs als Buchungen an.
     *
     * @param list<array<string, mixed>> $rows geprüfte Zeilen samt group_name und import_hash
     * @return int Zahl der angelegten Buchungen
     */
    public function importRows(array $rows, FinanceAccount $account, ?int $actorId): int
    {
        $imported = 0;

        Capsule::connection()->transaction(function () use ($rows, $account, $actorId, &$imported): void {
            $runningNumber = $this->reserveRunningNumbers(count($rows));
            foreach ($rows as $row) {
                $groupName = $row['group_name'];
                $finance = Finance::create([
                    'running_number' => $runningNumber,
                    'invoice_date' => $row['invoice_date'],
                    'payment_date' => $row['payment_date'],
                    'description' => $row['description'],
                    'group_name' => $groupName,
                    // Kanonische Gruppen-ID mitschreiben, sonst fehlen die
                    // importierten Buchungen in den Budget-Ist-Werten.
                    'finance_group_id' => $groupName !== null
                        ? FinanceGroup::firstOrCreate(['name' => $groupName])->id
                        : null,
                    'type' => $row['type'],
                    'amount' => $row['amount'],
                    'finance_account_id' => $account->id,
                    'payment_method' => $account->paymentMethod(),
                    'import_hash' => $row['import_hash'],
                ]);
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
                'group_name' => $original->group_name,
                'finance_group_id' => $original->finance_group_id,
                'type' => $original->type === Finance::TYPE_INCOME ? Finance::TYPE_EXPENSE : Finance::TYPE_INCOME,
                'amount' => $original->amount,
                'payment_method' => $original->payment_method,
                'finance_account_id' => $original->finance_account_id,
                'reversal_of_id' => $original->id,
            ]);

            $this->journal->recordReverse($reversal, $original, $actorId);
        });

        return $reversal;
    }
}
