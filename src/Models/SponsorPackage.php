<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class SponsorPackage extends Model
{
    protected $table = 'sponsor_packages';
    public $timestamps = false;

    protected $fillable = [
        'name',
        'description',
        'min_amount',
        'color',
    ];

    public function sponsorships()
    {
        return $this->hasMany(Sponsorship::class, 'package_id');
    }
}
