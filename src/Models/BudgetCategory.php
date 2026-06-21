<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class BudgetCategory extends Model
{
    protected $table = 'budget_categories';

    protected $fillable = [
        'fiscal_year_start',
        'finance_group_id',
        'type',
    ];

    protected $casts = [
        'fiscal_year_start' => 'integer',
        'finance_group_id' => 'integer',
    ];

    /** @return \Illuminate\Database\Eloquent\Relations\HasMany */
    public function items()
    {
        return $this->hasMany(BudgetItem::class, 'budget_category_id', 'id');
    }

    /** @return \Illuminate\Database\Eloquent\Relations\BelongsTo */
    public function financeGroup()
    {
        return $this->belongsTo(FinanceGroup::class, 'finance_group_id', 'id');
    }

    /**
     * Display name resolved from the linked finance group.
     */
    public function getGroupNameAttribute(): string
    {
        return $this->financeGroup->name ?? '';
    }
}
