<?php

declare(strict_types=1);

namespace App\Models;

use App\Models\Concerns\HasEntityAttachments;
use App\Models\Concerns\HasLogoAttachments;
use Illuminate\Database\Eloquent\Model;

class Sponsor extends Model
{
    use HasEntityAttachments;
    use HasLogoAttachments;

    /** Der Wert, unter dem Anhänge an diesem Sponsor hängen - Mediadaten, allgemeine Unterlagen. */
    public const ENTITY_TYPE = 'sponsor';

    /**
     * Logo-Dateien hängen getrennt davon: beliebig viele je Sponsor, in
     * Grafikformaten bis 50 MB (App\Util\SponsorLogoUpload), und mit einem
     * eigenen Recht für die Zuständigen einer Zusage.
     */
    public const LOGO_ENTITY_TYPE = 'sponsor_logo';

    protected $table = 'sponsors';
    public $timestamps = true;

    protected $fillable = [
        'type',
        'category_id',
        'name',
        'contact_person',
        'email',
        'phone',
        'address',
        'website',
        'notes',
        'requests_blocked',
        'requests_blocked_note',
        'created_by_user_id',
    ];

    protected $casts = [
        'requests_blocked' => 'boolean',
    ];

    public function category()
    {
        return $this->belongsTo(SponsorCategory::class, 'category_id');
    }

    public function createdByUser()
    {
        return $this->belongsTo(User::class, 'created_by_user_id');
    }

    public function sponsorships()
    {
        return $this->hasMany(Sponsorship::class, 'sponsor_id');
    }

    public function contacts()
    {
        return $this->hasMany(SponsoringContact::class, 'sponsor_id');
    }
}
