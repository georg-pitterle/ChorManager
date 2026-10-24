<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class SponsorCategory extends Model
{
    /**
     * Die allgemeinen Vorgaben, die 20261023120000_add_sponsor_categories
     * anlegt. Die Migration führt ihre eigene, eingefrorene Kopie; der Seed
     * braucht die Liste zur Laufzeit, weil reset-and-seed auch diese Tabelle
     * leert. SponsorCategoryFeatureTest hält beide Listen gleich.
     */
    public const DEFAULT_NAMES = [
        'Einzelhandel',
        'Gastronomie',
        'Handwerk',
        'Dienstleistung',
        'Banken & Versicherungen',
        'Öffentliche Einrichtungen',
        'Kultur',
        'Medien',
        'Privatperson',
    ];

    protected $table = 'sponsor_categories';
    public $timestamps = false;

    protected $fillable = [
        'name',
    ];

    public function sponsors()
    {
        return $this->hasMany(Sponsor::class, 'category_id');
    }
}
