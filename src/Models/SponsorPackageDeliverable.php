<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * Eine Leistung des Sponsors, die ein Paket vorsieht - die Vorlage, aus der
 * neue Vereinbarungen ihre Liste übernehmen.
 */
class SponsorPackageDeliverable extends Model
{
    protected $table = 'sponsor_package_deliverables';
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
