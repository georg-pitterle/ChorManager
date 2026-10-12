<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * Eine Gegenleistung, die der Verein einem Sponsor in einer Vereinbarung
 * zugesagt hat. Abgehakt ist sie, sobald delivered_at gesetzt ist.
 */
class SponsorshipBenefit extends Model
{
    protected $table = 'sponsorship_benefits';
    public $timestamps = false;

    protected $fillable = [
        'sponsorship_id',
        'description',
        'sort_order',
        'delivered_at',
        'delivered_by_user_id',
    ];

    protected $casts = [
        'delivered_at' => 'date',
    ];

    public function sponsorship()
    {
        return $this->belongsTo(Sponsorship::class, 'sponsorship_id');
    }

    public function deliveredBy()
    {
        return $this->belongsTo(User::class, 'delivered_by_user_id');
    }
}
