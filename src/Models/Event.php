<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
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
        'location',
        'registration_enabled',
        'registration_deadline',
        'registration_reminder_sent_at',
        'attendance_required'
    ];

    protected $casts = [
        'starts_at' => 'datetime',
        'ends_at' => 'datetime',
        'project_id' => 'integer',
        'event_type_id' => 'integer',
        'series_id' => 'integer',
        'registration_enabled' => 'boolean',
        'registration_deadline' => 'datetime',
        'registration_reminder_sent_at' => 'datetime',
        'attendance_required' => 'boolean',
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

    public function registrations()
    {
        return $this->hasMany(EventRegistration::class, 'event_id', 'id');
    }

    public function registrationDeadlineAt(): \Carbon\Carbon
    {
        $deadline = $this->registration_deadline ?? $this->starts_at;

        return \Carbon\Carbon::parse($deadline);
    }

    public function isRegistrationOpen(): bool
    {
        if (!(bool) $this->registration_enabled) {
            return false;
        }

        return $this->registrationDeadlineAt()->isFuture()
            && \Carbon\Carbon::parse($this->starts_at)->isFuture();
    }

    public function comments()
    {
        return $this->hasMany(Comment::class, 'entity_id', 'id')
            ->where('entity_type', 'event')
            ->orderBy('created_at', 'desc');
    }

    /**
     * Query for users eligible to register for / be counted for this event:
     * active users, restricted to project members for project-bound events,
     * otherwise all active users. This is the single source of truth for
     * event eligibility — every caller that needs to know "who counts for
     * this event" must build on this query rather than re-deriving the
     * predicate.
     */
    public function eligibleUsersQuery(): Builder
    {
        $query = User::where('is_active', 1);

        if ($this->project_id !== null) {
            $query->whereHas('projects', function ($projectQuery) {
                $projectQuery->where('projects.id', (int) $this->project_id);
            });
        }

        return $query;
    }
}
