<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\Finance;
use App\Models\FinanceAccount;
use Carbon\Carbon;

/**
 * Bestandsrechnung je Zahlungskreis (Barkassa, Bankkonten).
 *
 * Konvention: Der Anfangsbestand gilt zu Beginn des Stichtags (`opening_date`).
 * Bewegungen ab diesem Tag - einschließlich des Stichtags selbst - werden auf den
 * Anfangsbestand aufgerechnet. Buchungen ohne Zahldatum sind offene Posten und
 * verändern keinen Bestand.
 */
class FinanceAccountService
{
    /** @return \Illuminate\Support\Collection<int, FinanceAccount> */
    public function activeAccounts()
    {
        return FinanceAccount::where('is_active', true)
            ->orderBy('sort_order')
            ->orderBy('name')
            ->get();
    }

    /** @return \Illuminate\Support\Collection<int, FinanceAccount> */
    public function allAccounts()
    {
        return FinanceAccount::orderBy('sort_order')->orderBy('name')->get();
    }

    /**
     * Balance of one account at the end of the given day.
     */
    public function balanceAt(FinanceAccount $account, Carbon $date): float
    {
        $opening = (float) $account->opening_balance;
        $from = self::openingDate($account);
        $to = $date->format('Y-m-d');

        if ($to < $from) {
            return $opening;
        }

        return $opening + $this->movementSum($account->id, $from, $to);
    }

    /**
     * Balance at the start of the given day, i.e. before any booking of that day.
     */
    public function balanceBefore(FinanceAccount $account, Carbon $date): float
    {
        return $this->balanceAt($account, $date->copy()->subDay());
    }

    /**
     * Kassabericht for a fiscal year: opening balance, movements and closing
     * balance per account plus the totals across all accounts.
     *
     * @return array{accounts: list<array<string, mixed>>, totals: array<string, float>}
     */
    public function statement(Carbon $fiscalStart, Carbon $fiscalEnd): array
    {
        $accounts = [];
        $totals = ['opening' => 0.0, 'income' => 0.0, 'expense' => 0.0, 'closing' => 0.0];

        foreach ($this->allAccounts() as $account) {
            $opening = $this->balanceBefore($account, $fiscalStart);
            $income = $this->periodSum($account, 'income', $fiscalStart, $fiscalEnd);
            $expense = $this->periodSum($account, 'expense', $fiscalStart, $fiscalEnd);
            $closing = $this->balanceAt($account, $fiscalEnd);

            $accounts[] = [
                'account' => $account,
                'opening' => $opening,
                'income' => $income,
                'expense' => $expense,
                'closing' => $closing,
            ];

            $totals['opening'] += $opening;
            $totals['income'] += $income;
            $totals['expense'] += $expense;
            $totals['closing'] += $closing;
        }

        return ['accounts' => $accounts, 'totals' => $totals];
    }

    /**
     * Resolves the account a bank statement belongs to via its IBAN.
     */
    public function findByIban(?string $iban): ?FinanceAccount
    {
        $normalized = FinanceAccount::normalizeIban($iban);
        if ($normalized === null) {
            return null;
        }

        foreach ($this->allAccounts() as $account) {
            if (FinanceAccount::normalizeIban($account->iban) === $normalized) {
                return $account;
            }
        }

        return null;
    }

    /**
     * Fallback for bookings that only carry the legacy payment method.
     */
    public function defaultAccountForPaymentMethod(string $paymentMethod): ?FinanceAccount
    {
        $type = $paymentMethod === 'cash' ? FinanceAccount::TYPE_CASH : FinanceAccount::TYPE_BANK;

        return FinanceAccount::where('type', $type)
            ->where('is_active', true)
            ->orderBy('sort_order')
            ->orderBy('id')
            ->first();
    }

    private function movementSum(int $accountId, string $from, string $to): float
    {
        $income = (float) Finance::where('finance_account_id', $accountId)
            ->where('type', 'income')
            ->whereBetween('payment_date', [$from, $to])
            ->sum('amount');
        $expense = (float) Finance::where('finance_account_id', $accountId)
            ->where('type', 'expense')
            ->whereBetween('payment_date', [$from, $to])
            ->sum('amount');

        return $income - $expense;
    }

    /**
     * Bewegungssumme eines Kontos in der Periode. Der Anfangsbestand deckt bereits
     * alles vor dem Stichtag ab, deshalb zählt frühestens der Stichtag selbst -
     * sonst ginge Anfangsbestand + Einnahmen - Ausgaben nicht auf den Endbestand auf.
     */
    private function periodSum(FinanceAccount $account, string $type, Carbon $from, Carbon $to): float
    {
        $effectiveFrom = max($from->format('Y-m-d'), self::openingDate($account));
        $until = $to->format('Y-m-d');

        if ($until < $effectiveFrom) {
            return 0.0;
        }

        return (float) Finance::where('finance_account_id', $account->id)
            ->where('type', $type)
            ->whereBetween('payment_date', [$effectiveFrom, $until])
            ->sum('amount');
    }

    /**
     * Stichtag des Anfangsbestands als Y-m-d-String.
     */
    public static function openingDate(FinanceAccount $account): string
    {
        return $account->opening_date instanceof Carbon
            ? $account->opening_date->format('Y-m-d')
            : (string) $account->opening_date;
    }
}
