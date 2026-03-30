<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Sponsorship extends Model
{
    protected $table = 'sponsorships';
    public $timestamps = true;

    protected $fillable = [
        'sponsor_id',
        'project_id',
        'package_id',
        'assigned_user_id',
        'amount',
        'status',
        'start_date',
        'end_date',
        'notes',
    ];

    protected $casts = [
        'amount'     => 'decimal:2',
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

    public function contacts()
    {
        return $this->hasMany(SponsoringContact::class, 'sponsorship_id');
    }

    public function attachments()
    {
        return $this->hasMany(Attachment::class, 'entity_id', 'id')->where('entity_type', 'sponsorship');
    }
}
