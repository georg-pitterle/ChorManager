<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class BudgetCategory extends Model
{
    protected $table = 'budget_categories';

    protected $fillable = [
        'fiscal_year_start',
        'group_name',
        'type',
    ];

    protected $casts = [
        'fiscal_year_start' => 'integer',
    ];

    /** @return \Illuminate\Database\Eloquent\Relations\HasMany */
    public function items()
    {
        return $this->hasMany(BudgetItem::class, 'budget_category_id', 'id');
    }
}
