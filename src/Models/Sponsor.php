<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Sponsor extends Model
{
    protected $table = 'sponsors';
    public $timestamps = true;

    protected $fillable = [
        'type',
        'name',
        'contact_person',
        'email',
        'phone',
        'address',
        'website',
        'notes',
        'status',
    ];

    public function sponsorships()
    {
        return $this->hasMany(Sponsorship::class, 'sponsor_id');
    }

    public function contacts()
    {
        return $this->hasMany(SponsoringContact::class, 'sponsor_id');
    }
}
