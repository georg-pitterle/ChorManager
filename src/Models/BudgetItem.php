<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class BudgetItem extends Model
{
    protected $table = 'budget_items';

    protected $fillable = [
        'budget_category_id',
        'description',
        'planned_amount',
    ];

    protected $casts = [
        'planned_amount' => 'decimal:2',
    ];

    /** @return \Illuminate\Database\Eloquent\Relations\BelongsTo */
    public function category()
    {
        return $this->belongsTo(BudgetCategory::class, 'budget_category_id', 'id');
    }
}
