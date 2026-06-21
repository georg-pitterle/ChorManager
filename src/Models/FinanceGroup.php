<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class FinanceGroup extends Model
{
    protected $table = 'finance_groups';

    protected $fillable = [
        'name',
    ];

    /** @return \Illuminate\Database\Eloquent\Relations\HasMany */
    public function finances()
    {
        return $this->hasMany(Finance::class, 'finance_group_id', 'id');
    }

    /** @return \Illuminate\Database\Eloquent\Relations\HasMany */
    public function budgetCategories()
    {
        return $this->hasMany(BudgetCategory::class, 'finance_group_id', 'id');
    }
}
