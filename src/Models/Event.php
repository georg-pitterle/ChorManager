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
        'event_date'
    ];

    protected $casts = [
        'event_date' => 'datetime',
    ];

    public function project()
    {
        return $this->belongsTo(Project::class, 'project_id', 'id');
    }

    public function attendances()
    {
        return $this->hasMany(Attendance::class, 'event_id', 'id');
    }
}
