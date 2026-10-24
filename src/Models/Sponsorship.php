<?php

declare(strict_types=1);

namespace App\Models;

use App\Models\Concerns\HasEntityAttachments;
use Illuminate\Database\Eloquent\Model;

class Sponsorship extends Model
{
    use HasEntityAttachments;

    /** Der Wert, unter dem Anhänge an dieser Vereinbarung hängen. */
    public const ENTITY_TYPE = 'sponsorship';

    protected $table = 'sponsorships';
    public $timestamps = true;

    protected $fillable = [
        'sponsor_id',
        'project_id',
        'package_id',
        'assigned_user_id',
        'created_by_user_id',
        'amount',
        'paid_at',
        'status',
        'start_date',
        'end_date',
        'notes',
        'needs_logo_print',
        'needs_logo_online',
    ];

    protected $casts = [
        'needs_logo_print' => 'boolean',
        'needs_logo_online' => 'boolean',
        'amount'     => 'decimal:2',
        'paid_at'    => 'date',
        'start_date' => 'date',
        'end_date'   => 'date',
    ];

    public function sponsor()
    {
        return $this->belongsTo(Sponsor::class, 'sponsor_id');
    }

    public function project()
    {
        return $this->belongsTo(Project::class, 'project_id');
    }

    public function package()
    {
        return $this->belongsTo(SponsorPackage::class, 'package_id');
    }

    public function assignedUser()
    {
        return $this->belongsTo(User::class, 'assigned_user_id');
    }

    /**
     * Wer die Vereinbarung erfasst hat. Entscheidet zusammen mit
     * can_create_own_sponsorships darüber, wer sie ändern darf - "zuständig"
     * (assignedUser) ist etwas anderes und wird vom Sponsoring-Team vergeben.
     */
    public function createdByUser()
    {
        return $this->belongsTo(User::class, 'created_by_user_id');
    }

    /**
     * Die zugesagten Gegenleistungen, in der ausgehandelten Reihenfolge.
     */
    public function benefits()
    {
        return $this->hasMany(SponsorshipBenefit::class, 'sponsorship_id')->orderBy('sort_order')->orderBy('id');
    }

    /**
     * Was der Sponsor liefert, in der ausgehandelten Reihenfolge.
     */
    public function deliverables()
    {
        return $this->hasMany(SponsorshipDeliverable::class, 'sponsorship_id')->orderBy('sort_order')->orderBy('id');
    }

    public function contacts()
    {
        return $this->hasMany(SponsoringContact::class, 'sponsorship_id');
    }
}
