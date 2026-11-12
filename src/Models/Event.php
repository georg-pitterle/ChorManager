<?php

declare(strict_types=1);

namespace App\Models;

use App\Models\Concerns\HasAudienceConditionSets;
use App\Models\Concerns\HasEntityComments;
use Carbon\Carbon;
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

    /**
     * Der Name der Terminart - oder eine leere Zeichenkette, wenn keine
     * eingetragen ist.
     *
     * Der Name stand bis Lauf 72 zweimal: in `event_types.name` und
     * zusätzlich als Zeichenkette in `events.type`. Die Spalte wurde beim
     * Anlegen und Bearbeiten eines Termins mitgeschrieben, beim Umbenennen der
     * Terminart dagegen nicht - die Anwesenheitsseite las sie und zeigte
     * deshalb den alten Namen weiter an, während Terminliste und Kalender
     * schon die Beziehung nahmen. Migration 20261112120000 hat die Spalte
     * entfernt.
     *
     * Die Regel steht hier und nicht in jeder Ansicht: Sie stand bis dahin nur
     * im EventController, und die zweite Stelle, die denselben Namen brauchte,
     * griff zur Spalte - genau der Weg, auf dem die beiden Ansichten
     * auseinanderliefen.
     *
     * Leer statt eines Vorgabenamens: `events.event_type_id` darf leer sein,
     * und die Spalte log in diesem Fall - sie trug das hartverdrahtete "Probe"
     * aus `EventController::store()`, obwohl niemand eine Terminart gewählt
     * hatte. Was dasteht, wenn nichts eingetragen ist, entscheidet jetzt die
     * Ansicht; beide zeigen einen Gedankenstrich.
     *
     * Die Beziehung sollte vorab geladen sein (`with('eventType')` oder
     * `setRelation()`), sonst holt Eloquent sie je Termin nach. Falsch wird die
     * Antwort dadurch nicht, nur teuer.
     */
    public function typeName(): string
    {
        return (string) ($this->eventType->name ?? '');
    }

    public function registrations()
    {
        return $this->hasMany(EventRegistration::class, 'event_id', 'id');
    }

    public function registrationDeadlineAt(): Carbon
    {
        $deadline = $this->registration_deadline ?? $this->starts_at;

        return Carbon::parse($deadline);
    }

    public function isRegistrationOpen(): bool
    {
        if (!(bool) $this->registration_enabled) {
            return false;
        }

        return $this->registrationDeadlineAt()->isFuture()
            && Carbon::parse($this->starts_at)->isFuture();
    }
}
