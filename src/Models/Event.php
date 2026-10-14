<?php

declare(strict_types=1);

namespace App\Models;

use App\Models\Concerns\HasAudienceConditionSets;
use App\Models\Concerns\HasEntityComments;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Event extends Model
{
    use HasAudienceConditionSets;
    use HasEntityComments;

    /** Der Wert, unter dem Notizen und Anhänge an diesem Termin hängen. */
    public const ENTITY_TYPE = 'event';

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

    /**
     * Zielgruppen-Zeilen, aufsteigend nach `id` - die Reihenfolge, in der sie
     * angelegt wurden. Die Sortierung steht hier und nicht erst beim Nachladen
     * in HasAudienceConditionSets: Sonst kommt die vorab geladene Zielgruppe in
     * der Reihenfolge der Datenbank und die nachgeladene nach `id`, und
     * derselbe Termin bekommt je nach Aufrufer zwei verschiedene
     * Zielgruppen-Beschreibungen.
     */
    public function audienceFilters(): HasMany
    {
        return $this->hasMany(AudienceFilter::class, 'event_id', 'id')
            ->orderBy('audience_filters.id');
    }

    /**
     * Termine, deren Zielgruppe in mindestens einer Zeile das Projekt nennt.
     */
    public function scopeForProject(Builder $query, int $projectId): Builder
    {
        return $query->whereHas('audienceFilters.conditions', static function ($condition) use ($projectId): void {
            $condition->where('category', AudienceFilterCondition::CATEGORY_PROJECT)->where('reference_id', $projectId);
        });
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
}
