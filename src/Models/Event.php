<?php

declare(strict_types=1);

namespace App\Models;

use App\Services\Audience\AudienceFilterService;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

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

    public function audienceFilters(): HasMany
    {
        return $this->hasMany(AudienceFilter::class, 'event_id', 'id');
    }

    /**
     * Bedingungsmengen der Zielgruppe, eine je Zeile. Nutzt eine vorab geladene
     * Beziehung (`with('audienceFilters.conditions')`).
     *
     * @return list<array<string, list<int>>>
     */
    public function audienceConditionSets(): array
    {
        $filters = $this->relationLoaded('audienceFilters')
            ? $this->audienceFilters
            : $this->audienceFilters()->with('conditions')->orderBy('id')->get();

        return $filters->map(static fn (AudienceFilter $filter): array => $filter->conditionSet())->values()->all();
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

    /**
     * Notizen des Termins. Private Notizen bleiben hier aussen vor: die Relation
     * kennt die angemeldete Person nicht, und ohne diese Grenze hängt es am
     * jeweiligen Aufrufer, ob eine fremde private Notiz in der Ausgabe landet.
     * Wer auch die eigenen privaten Notizen braucht, setzt die Relation mit
     * Comment::visibleTo() bewusst selbst.
     *
     * Die neueste zuerst, `id` bricht den Gleichstand: `comments.created_at`
     * steht auf Sekunden genau, und zwei Notizen in derselben Sekunde sind keine
     * Ausnahme. Ohne den zweiten Schlüssel wechselt ihre Reihenfolge zwischen
     * zwei Aufrufen.
     */
    public function comments()
    {
        return $this->hasMany(Comment::class, 'entity_id', 'id')
            ->where('entity_type', 'event')
            ->where('is_private', false)
            ->orderBy('created_at', 'desc')
            ->orderBy('id', 'desc');
    }

    /**
     * Query for users eligible to register for / be counted for this event:
     * active users that at least one row of the audience matches (within a
     * row: OR inside a category, AND across categories). An event without any
     * row matches nobody - "all members" is a row without conditions. This is
     * the single source of truth for event eligibility — every caller that needs
     * to know "who counts for this event" must build on this query rather than
     * re-deriving the predicate.
     *
     * Geladen werden nur User::LIST_COLUMNS. Die Aufrufer reichen die Modelle
     * unverändert an Templates und Mail-Erzeugung weiter; der Passwort-Hash hat
     * dort nichts verloren. Wer eine weitere Spalte braucht, nimmt sie in
     * LIST_COLUMNS auf - nicht in einen eigenen select() an der Aufrufstelle,
     * sonst fällt die Grenze wieder auseinander.
     */
    public function eligibleUsersQuery(): Builder
    {
        return (new AudienceFilterService())
            ->membersQueryForSets($this->audienceConditionSets())
            ->select(array_map(static fn (string $column): string => 'users.' . $column, User::LIST_COLUMNS));
    }
}
