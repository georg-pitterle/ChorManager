<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class EventAudienceSource extends Model
{
    public const TYPE_PROJECT_MEMBERS = 'project_members';
    public const TYPE_ROLE = 'role';
    public const TYPE_USER = 'user';
    public const TYPE_VOICE_GROUP = 'voice_group';

    protected $table = 'event_audience_sources';
    public $timestamps = false;

    protected $fillable = [
        'event_id',
        'source_type',
        'reference_id',
    ];
}
