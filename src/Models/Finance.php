<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Finance extends Model
{
    protected $table = 'finances';
    public $timestamps = false;

    protected $fillable = [
        'running_number',
        'invoice_date',
        'payment_date',
        'description',
        'group_name',
        'finance_group_id',
        'type',
        'amount',
        'payment_method'
    ];

    protected $casts = [
        'invoice_date' => 'date',
        'payment_date' => 'date',
        'amount' => 'decimal:2',
    ];

    public function attachments()
    {
        return $this->hasMany(Attachment::class, 'entity_id', 'id')->where('entity_type', 'finance');
    }

    /** @return \Illuminate\Database\Eloquent\Relations\BelongsTo */
    public function financeGroup()
    {
        return $this->belongsTo(FinanceGroup::class, 'finance_group_id', 'id');
    }
}
