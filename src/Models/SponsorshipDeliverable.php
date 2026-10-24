<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * Eine Leistung, die der Sponsor in einer Vereinbarung zugesagt hat - Banner,
 * Vertrag, Sachleistung. Abgehakt ist sie, sobald received_at gesetzt ist.
 */
class SponsorshipDeliverable extends Model
{
    protected $table = 'sponsorship_deliverables';
    public $timestamps = false;

    protected $fillable = [
        'sponsorship_id',
        'description',
        'sort_order',
        'received_at',
        'received_by_user_id',
    ];

    protected $casts = [
        'received_at' => 'date',
    ];

    public function sponsorship()
    {
        return $this->belongsTo(Sponsorship::class, 'sponsorship_id');
    }

    public function receivedBy()
    {
        return $this->belongsTo(User::class, 'received_by_user_id');
    }
}
