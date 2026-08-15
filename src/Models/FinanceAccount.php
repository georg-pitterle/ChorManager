<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class FinanceAccount extends Model
{
    public const TYPE_CASH = 'cash';
    public const TYPE_BANK = 'bank';

    protected $table = 'finance_accounts';

    protected $fillable = [
        'name',
        'type',
        'iban',
        'opening_balance',
        'opening_date',
        'is_active',
        'sort_order',
    ];

    protected $casts = [
        'opening_balance' => 'decimal:2',
        'opening_date' => 'date',
        'is_active' => 'boolean',
        'sort_order' => 'integer',
    ];

    public function finances()
    {
        return $this->hasMany(Finance::class, 'finance_account_id', 'id');
    }

    /**
     * Payment method mirrored onto the booking so report, PDF and table sorting
     * keep working on the denormalized column.
     */
    public function paymentMethod(): string
    {
        return $this->type === self::TYPE_CASH ? 'cash' : 'bank_transfer';
    }

    /**
     * Normalizes an IBAN for comparison: no spaces, upper case.
     */
    public static function normalizeIban(?string $iban): ?string
    {
        if ($iban === null) {
            return null;
        }

        $normalized = strtoupper(preg_replace('/\s+/', '', trim($iban)) ?? '');

        return $normalized === '' ? null : $normalized;
    }
}
