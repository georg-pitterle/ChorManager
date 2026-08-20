<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\FinanceAccount;

/**
 * Jede Buchung hängt an genau einem Zahlungskreis - die Spalte
 * `finances.finance_account_id` ist NOT NULL. Tests, die Buchungen direkt
 * anlegen (statt über den Controller), brauchen deshalb ein Konto.
 */
trait FinanceAccountFixture
{
    protected function fixtureAccountId(string $type = FinanceAccount::TYPE_BANK): int
    {
        $account = FinanceAccount::firstOrCreate(
            ['name' => 'Testkonto ' . $type],
            [
                'type' => $type,
                'iban' => null,
                'opening_balance' => '0.00',
                'opening_date' => '2000-01-01',
                'is_active' => true,
                'sort_order' => 999,
            ]
        );

        return (int) $account->id;
    }
}
