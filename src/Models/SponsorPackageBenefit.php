<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * Eine Gegenleistung, die ein Paket vorsieht - die Vorlage, aus der neue
 * Vereinbarungen ihre Leistungen übernehmen.
 */
class SponsorPackageBenefit extends Model
{
    protected $table = 'sponsor_package_benefits';
    public $timestamps = false;

    protected $fillable = [
        'package_id',
        'description',
        'sort_order',
    ];

    public function package()
    {
        return $this->belongsTo(SponsorPackage::class, 'package_id');
    }
}
