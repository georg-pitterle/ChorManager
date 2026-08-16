<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\Finance;
use App\Models\FinanceAccount;
use App\Models\FinanceGroup;
use App\Models\FinanceRevision;
use App\Models\Setting;
use Carbon\Carbon;

/**
 * Nachvollziehbarkeit des Kassabuchs: Änderungsjournal und Jahressperre.
 *
 * § 131 BAO verlangt, dass eine Korrektur den ursprünglichen Inhalt nicht
 * unkenntlich macht. Deshalb wird jede Anlage, Änderung und jedes Storno mit
 * seinen Vorher-Werten protokolliert; abgeschlossene Zeiträume lassen sich
 * sperren, danach ist nur noch eine Gegenbuchung möglich.
 */
class FinanceJournalService
{
    public const CLOSED_UNTIL_KEY = 'finance_closed_until';

    /** Felder, deren Änderung protokolliert wird. */
    private const TRACKED_FIELDS = [
        'invoice_date',
        'payment_date',
        'description',
        'group_name',
        'finance_group_id',
        'type',
        'amount',
        'payment_method',
        'finance_account_id',
    ];

    /**
     * Anzeigenamen der protokollierten Felder. Im Journal steht sonst der reine
     * Spaltenname der Datenbank, und der ist für den Kassier nicht lesbar.
     */
    public const FIELD_LABELS = [
        'invoice_date' => 'Rechnungsdatum',
        'payment_date' => 'Zahldatum',
        'description' => 'Beschreibung',
        'group_name' => 'Gruppe',
        'finance_group_id' => 'Gruppenzuordnung',
        'type' => 'Art',
        'amount' => 'Betrag',
        'payment_method' => 'Zahlungsart',
        'finance_account_id' => 'Konto',
        'reversal_of' => 'Storno zu',
    ];

    /** @var array<int, string>|null */
    private ?array $accountNames = null;

    /** @var array<int, string>|null */
    private ?array $groupNames = null;

    /**
     * Letzter abgeschlossener Tag, oder null wenn nichts gesperrt ist.
     */
    public function closedUntil(): ?Carbon
    {
        $setting = Setting::find(self::CLOSED_UNTIL_KEY);
        $value = $setting?->setting_value;
        if (!is_string($value) || trim($value) === '') {
            return null;
        }

        try {
            return Carbon::parse($value)->startOfDay();
        } catch (\Exception) {
            return null;
        }
    }

    public function setClosedUntil(?string $date): void
    {
        if ($date === null || trim($date) === '') {
            Setting::where('setting_key', self::CLOSED_UNTIL_KEY)->delete();
            return;
        }

        Setting::updateOrCreate(
            ['setting_key' => self::CLOSED_UNTIL_KEY],
            ['setting_value' => Carbon::parse($date)->format('Y-m-d')]
        );
    }

    /**
     * Ist der Zeitraum, in den diese Zahlung fällt, bereits abgeschlossen?
     * Offene Posten ohne Zahldatum sind nie gesperrt.
     */
    public function isLocked(?string $paymentDate): bool
    {
        $closedUntil = $this->closedUntil();
        if ($closedUntil === null || $paymentDate === null || trim($paymentDate) === '') {
            return false;
        }

        return $paymentDate <= $closedUntil->format('Y-m-d');
    }

    public function isFinanceLocked(Finance $finance): bool
    {
        $paymentDate = $finance->payment_date instanceof Carbon
            ? $finance->payment_date->format('Y-m-d')
            : (string) $finance->payment_date;

        return $this->isLocked($paymentDate === '' ? null : $paymentDate);
    }

    public function recordCreate(Finance $finance, ?int $userId): void
    {
        $this->write($finance->id, $userId, FinanceRevision::ACTION_CREATE, []);
    }

    /**
     * Protokolliert eine Änderung. Nur tatsächlich geänderte Felder landen im
     * Journal; ohne Unterschied wird kein Eintrag geschrieben.
     *
     * @param array<string, mixed> $before
     * @param array<string, mixed> $after
     */
    public function recordUpdate(Finance $finance, array $before, array $after, ?int $userId): void
    {
        $changes = self::diff($before, $after);
        if ($changes === []) {
            return;
        }

        $this->write($finance->id, $userId, FinanceRevision::ACTION_UPDATE, $changes);
    }

    public function recordReverse(Finance $reversal, Finance $original, ?int $userId): void
    {
        $this->write($reversal->id, $userId, FinanceRevision::ACTION_REVERSE, [
            'reversal_of' => ['from' => null, 'to' => $original->running_number],
        ]);
    }

    /**
     * Bereitet einen Änderungssatz für die Anzeige auf: deutsche Feldnamen und
     * lesbare Werte statt Spaltennamen, Fremdschlüsseln und Enum-Codes.
     *
     * @param array<string, array{from: mixed, to: mixed}> $changes
     * @return list<array{label: string, from: string|null, to: string|null}>
     */
    public function describeChanges(array $changes): array
    {
        $described = [];
        foreach ($changes as $field => $change) {
            $described[] = [
                'label' => self::FIELD_LABELS[$field] ?? $field,
                'from' => $this->formatValue($field, $change['from'] ?? null),
                'to' => $this->formatValue($field, $change['to'] ?? null),
            ];
        }

        return $described;
    }

    /**
     * Null steht für "kein Wert" und wird in der Anzeige zu "leer".
     */
    private function formatValue(string $field, mixed $value): ?string
    {
        if ($value === null || $value === '') {
            return null;
        }

        $value = (string) $value;

        return match ($field) {
            'type' => $value === 'income' ? 'Eingang' : 'Ausgang',
            'payment_method' => $value === 'cash' ? 'Bar' : 'Überweisung',
            'finance_account_id' => $this->accountName((int) $value),
            'finance_group_id' => $this->groupName((int) $value),
            'invoice_date', 'payment_date' => $this->formatDate($value),
            'amount' => number_format((float) $value, 2, ',', '.') . ' €',
            'reversal_of' => 'Nr. ' . $value,
            default => $value,
        };
    }

    private function formatDate(string $value): string
    {
        try {
            return Carbon::parse($value)->format('d.m.Y');
        } catch (\Exception) {
            return $value;
        }
    }

    /**
     * Gelöschte Konten und Gruppen bleiben im Journal referenziert; dann bleibt
     * nur die ID, damit der Eintrag nicht stillschweigend leer wird.
     */
    private function accountName(int $id): string
    {
        $this->accountNames ??= FinanceAccount::pluck('name', 'id')
            ->map(static fn($name): string => (string) $name)
            ->all();

        return $this->accountNames[$id] ?? ('Konto #' . $id);
    }

    private function groupName(int $id): string
    {
        $this->groupNames ??= FinanceGroup::pluck('name', 'id')
            ->map(static fn($name): string => (string) $name)
            ->all();

        return $this->groupNames[$id] ?? ('Gruppe #' . $id);
    }

    /**
     * @param array<string, mixed> $before
     * @param array<string, mixed> $after
     * @return array<string, array{from: mixed, to: mixed}>
     */
    public static function diff(array $before, array $after): array
    {
        $changes = [];
        foreach (self::TRACKED_FIELDS as $field) {
            if (!array_key_exists($field, $after)) {
                continue;
            }

            $from = self::stringify($before[$field] ?? null);
            $to = self::stringify($after[$field]);
            if ($from === $to) {
                continue;
            }

            $changes[$field] = ['from' => $from, 'to' => $to];
        }

        return $changes;
    }

    /**
     * @return array<string, mixed>
     */
    public static function snapshot(Finance $finance): array
    {
        $snapshot = [];
        foreach (self::TRACKED_FIELDS as $field) {
            $snapshot[$field] = self::stringify($finance->getAttribute($field));
        }

        return $snapshot;
    }

    private static function stringify(mixed $value): ?string
    {
        if ($value === null) {
            return null;
        }

        if ($value instanceof Carbon) {
            return $value->format('Y-m-d');
        }

        if (is_bool($value)) {
            return $value ? '1' : '0';
        }

        $stringValue = (string) $value;

        // Beträge normalisieren, damit "10" und "10.00" nicht als Änderung gelten.
        if (is_numeric($stringValue) && str_contains($stringValue, '.')) {
            return rtrim(rtrim($stringValue, '0'), '.');
        }

        return $stringValue;
    }

    /**
     * @param array<string, array{from: mixed, to: mixed}> $changes
     */
    private function write(int $financeId, ?int $userId, string $action, array $changes): void
    {
        FinanceRevision::create([
            'finance_id' => $financeId,
            'user_id' => $userId,
            'action' => $action,
            'change_set' => $changes === [] ? null : json_encode($changes, JSON_UNESCAPED_UNICODE),
            'created_at' => Carbon::now()->format('Y-m-d H:i:s'),
        ]);
    }
}
