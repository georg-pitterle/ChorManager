<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Event extends Model
{
    protected $table = 'events';
    public $timestamps = false;

    protected $fillable = [
        'title',
        'project_id',
        'starts_at',
        'ends_at',
        'event_type_id',
        'series_id',
        'type',
        'location'
    ];

    protected $casts = [
        'starts_at' => 'datetime',
        'ends_at' => 'datetime',
        'project_id' => 'integer',
        'event_type_id' => 'integer',
        'series_id' => 'integer',
    ];

    public function eventType()
    {
        return $this->belongsTo(EventType::class, 'event_type_id', 'id');
    }

    public function series()
    {
        return $this->belongsTo(EventSeries::class, 'series_id', 'id');
    }

    public function project()
    {
        return $this->belongsTo(Project::class, 'project_id', 'id');
    }

    public function attendances()
    {
        return $this->hasMany(Attendance::class, 'event_id', 'id');
    }
}
