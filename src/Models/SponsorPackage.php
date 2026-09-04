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

    /**
     * Gleicher Cast wie bei Sponsorship::$amount - beide Beträge werden
     * miteinander verglichen, und ohne den Cast stünde dort eine Zeichenkette
     * gegen eine Zahl.
     *
     * @var array<string, string>
     */
    protected $casts = [
        'min_amount' => 'decimal:2',
    ];

    public function sponsorships()
    {
        return $this->hasMany(Sponsorship::class, 'package_id');
    }
}
