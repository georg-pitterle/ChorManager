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

    /** @return \Illuminate\Database\Eloquent\Relations\BelongsToMany */
    public function finances()
    {
        return $this->belongsToMany(Finance::class, 'finance_group_assignments', 'finance_group_id', 'finance_id');
    }
}
