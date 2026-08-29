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
        'requests_blocked',
        'requests_blocked_note',
    ];

    protected $casts = [
        'requests_blocked' => 'boolean',
    ];

    public function sponsorships()
    {
        return $this->hasMany(Sponsorship::class, 'sponsor_id');
    }

    public function contacts()
    {
        return $this->hasMany(SponsoringContact::class, 'sponsor_id');
    }

    /**
     * Anhänge am Sponsor selbst - Logos, Mediadaten, allgemeine Unterlagen, die
     * zu keiner einzelnen Vereinbarung gehören. Gleiches Muster wie
     * Sponsorship::attachments().
     */
    public function attachments()
    {
        return $this->hasMany(Attachment::class, 'entity_id', 'id')->where('entity_type', 'sponsor');
    }
}
