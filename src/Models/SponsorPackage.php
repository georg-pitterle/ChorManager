<?php

declare(strict_types=1);

namespace App\Models;

use App\Util\SponsorPackageColor;
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
        'needs_logo_print',
        'needs_logo_online',
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
        'needs_logo_print' => 'boolean',
        'needs_logo_online' => 'boolean',
    ];

    /**
     * Was das Paket an Gegenleistungen vorsieht - die Vorgabe für neue
     * Vereinbarungen mit diesem Paket.
     */
    public function benefits()
    {
        return $this->hasMany(SponsorPackageBenefit::class, 'package_id')->orderBy('sort_order')->orderBy('id');
    }

    public function deliverables()
    {
        return $this->hasMany(SponsorPackageDeliverable::class, 'package_id')->orderBy('sort_order')->orderBy('id');
    }

    /**
     * Abzeichen-Klassen des Pakets mit lesbarer Schrift (`text-bg-*`).
     * Als Attribut, damit jedes Template `pkg.badge_class` schreibt und keines
     * die Klasse selbst aus der Farbe zusammensetzt.
     */
    public function getBadgeClassAttribute(): string
    {
        return SponsorPackageColor::badgeClass($this->color);
    }

    public function getColorLabelAttribute(): string
    {
        return SponsorPackageColor::label($this->color);
    }

    public function sponsorships()
    {
        return $this->hasMany(Sponsorship::class, 'package_id');
    }
}
