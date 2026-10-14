<?php

declare(strict_types=1);

namespace App\Models;

use App\Models\Concerns\HasEntityAttachments;
use Illuminate\Database\Eloquent\Model;

class Sponsor extends Model
{
    use HasEntityAttachments;

    /** Der Wert, unter dem Anhänge an diesem Sponsor hängen - Logos, Mediadaten, allgemeine Unterlagen. */
    public const ENTITY_TYPE = 'sponsor';

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
        'requests_blocked',
        'requests_blocked_note',
        'created_by_user_id',
    ];

    protected $casts = [
        'requests_blocked' => 'boolean',
    ];

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
