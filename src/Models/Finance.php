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
        'payment_method',
        'finance_account_id',
        'import_hash',
        'reversal_of_id'
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

    /** @return \Illuminate\Database\Eloquent\Relations\BelongsTo */
    public function financeAccount()
    {
        return $this->belongsTo(FinanceAccount::class, 'finance_account_id', 'id');
    }

    /** Die Buchung, die diese Gegenbuchung storniert. */
    public function reversalOf()
    {
        return $this->belongsTo(Finance::class, 'reversal_of_id', 'id');
    }

    /** Die Gegenbuchung, die diese Buchung storniert. */
    public function reversedBy()
    {
        return $this->hasOne(Finance::class, 'reversal_of_id', 'id');
    }

    /**
     * Storno-Status bewusst als Accessor statt als isReversal()-Methode und
     * statt eines direkten Relationszugriffs im Template:
     *
     * - Eine parameterlose Methode haelt Eloquent beim Property-Zugriff fuer eine
     *   Relation und wirft "must return a relationship instance".
     * - Auf eine ungeladene Relation greift Twig per Methodenaufruf zu und
     *   bekommt das Relation-Objekt zurueck, das immer truthy ist.
     *
     * Accessoren liefern dagegen zuverlaessig den Wahrheitswert.
     */
    public function getIsReversalAttribute(): bool
    {
        return $this->reversal_of_id !== null;
    }

    public function getIsReversedAttribute(): bool
    {
        if ($this->relationLoaded('reversedBy')) {
            return $this->getRelation('reversedBy') !== null;
        }

        return $this->reversedBy()->exists();
    }
}
