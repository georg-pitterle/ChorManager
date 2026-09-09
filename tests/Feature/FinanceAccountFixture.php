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
    /**
     * Konten, die *dieser* Test angelegt hat - nicht die bereits vorhandenen.
     *
     * @var list<int>
     */
    private array $createdFixtureAccountIds = [];

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

        // firstOrCreate() verschweigt, ob die Zeile neu ist. Ohne diesen Vermerk
        // kann ein Test ohne umschließende Transaktion nicht wissen, was er
        // wieder wegzuräumen hat - und das Konto bleibt nach dem Lauf stehen.
        if ($account->wasRecentlyCreated) {
            $this->createdFixtureAccountIds[] = (int) $account->id;
        }

        return (int) $account->id;
    }

    /**
     * Nimmt die hier angelegten Konten zurück.
     *
     * Tests, die jeden Fall in beginTransaction()/rollBack() einschließen,
     * brauchen den Aufruf nicht - der Rollback erledigt es. Wer ohne
     * Transaktion arbeitet, ruft ihn in tearDown() auf, sonst meldet
     * tests/Guard/DatabaseLeakGuardTest die liegengebliebene Zeile.
     */
    protected function deleteFixtureAccounts(): void
    {
        if ($this->createdFixtureAccountIds === []) {
            return;
        }

        FinanceAccount::whereIn('id', $this->createdFixtureAccountIds)->delete();
        $this->createdFixtureAccountIds = [];
    }
}
