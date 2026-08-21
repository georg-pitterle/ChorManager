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

    public function attendances()
    {
        return $this->hasMany(Attendance::class, 'event_id', 'id');
    }

    public function audienceSources()
    {
        return $this->hasMany(EventAudienceSource::class, 'event_id', 'id');
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

    /**
     * Notizen des Termins. Private Notizen bleiben hier aussen vor: die Relation
     * kennt die angemeldete Person nicht, und ohne diese Grenze haengt es am
     * jeweiligen Aufrufer, ob eine fremde private Notiz in der Ausgabe landet.
     * Wer auch die eigenen privaten Notizen braucht, setzt die Relation mit
     * Comment::visibleTo() bewusst selbst.
     */
    public function comments()
    {
        return $this->hasMany(Comment::class, 'entity_id', 'id')
            ->where('entity_type', 'event')
            ->where('is_private', false)
            ->orderBy('created_at', 'desc');
    }

    /**
     * Query for users eligible to register for / be counted for this event:
     * active users, restricted to the configured audience sources (union of
     * project members, roles, voice groups and single users). An event without
     * any source counts for all active users. This is the single source of
     * truth for event eligibility — every caller that needs to know "who counts
     * for this event" must build on this query rather than re-deriving the
     * predicate.
     */
    public function eligibleUsersQuery(): Builder
    {
        $query = User::where('is_active', 1);

        $sources = $this->relationLoaded('audienceSources')
            ? $this->audienceSources
            : $this->audienceSources()->get();

        if ($sources->isEmpty()) {
            return $query;
        }

        $projectIds = $this->referenceIdsFor($sources, EventAudienceSource::TYPE_PROJECT_MEMBERS);
        $roleIds = $this->referenceIdsFor($sources, EventAudienceSource::TYPE_ROLE);
        $voiceGroupIds = $this->referenceIdsFor($sources, EventAudienceSource::TYPE_VOICE_GROUP);
        $userIds = $this->referenceIdsFor($sources, EventAudienceSource::TYPE_USER);

        // Quellen sind hinterlegt, aber keine davon ist auswertbar - etwa weil
        // source_type leer ist oder einen hier unbekannten Typ traegt. Eine leere
        // Bedingungsgruppe wuerde die Einschraenkung stillschweigend aufheben und
        // den Termin fuer alle aktiven Mitglieder oeffnen. Eine Zielgruppe, die
        // sich nicht aufloesen laesst, umfasst niemanden.
        if ($projectIds === [] && $roleIds === [] && $voiceGroupIds === [] && $userIds === []) {
            return $query->whereRaw('1 = 0');
        }

        $query->where(function ($grouped) use ($projectIds, $roleIds, $voiceGroupIds, $userIds) {
            if ($projectIds !== []) {
                $grouped->orWhereHas('projects', function ($q) use ($projectIds) {
                    $q->whereIn('project_id', $projectIds);
                });
            }
            if ($roleIds !== []) {
                $grouped->orWhereHas('roles', function ($q) use ($roleIds) {
                    $q->whereIn('role_id', $roleIds);
                });
            }
            if ($voiceGroupIds !== []) {
                $grouped->orWhereHas('voiceGroups', function ($q) use ($voiceGroupIds) {
                    $q->whereIn('voice_group_id', $voiceGroupIds);
                });
            }
            if ($userIds !== []) {
                $grouped->orWhereIn('users.id', $userIds);
            }
        });

        return $query;
    }

    /**
     * @param \Illuminate\Support\Collection<int, EventAudienceSource> $sources
     * @return array<int, int>
     */
    private function referenceIdsFor($sources, string $type): array
    {
        return $sources
            ->where('source_type', $type)
            ->pluck('reference_id')
            ->map(static fn ($id): int => (int) $id)
            ->unique()
            ->values()
            ->all();
    }
}
