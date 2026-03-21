<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class SponsoringContact extends Model
{
    protected $table = 'sponsoring_contacts';
    public $timestamps = false;

    protected $fillable = [
        'sponsor_id',
        'sponsorship_id',
        'user_id',
        'contact_date',
        'type',
        'summary',
        'follow_up_date',
        'follow_up_done',
    ];

    protected $casts = [
        'contact_date'   => 'date',
        'follow_up_date' => 'date',
        'follow_up_done' => 'boolean',
    ];

    public function sponsor()
    {
        return $this->belongsTo(Sponsor::class, 'sponsor_id');
    }

    public function sponsorship()
    {
        return $this->belongsTo(Sponsorship::class, 'sponsorship_id');
    }

    public function user()
    {
        return $this->belongsTo(User::class, 'user_id');
    }
}
