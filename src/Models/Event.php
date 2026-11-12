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

    /**
     * Der Name der Terminart, so wie sie heute heißt.
     *
     * Der Name steht zweimal: in `event_types.name` und zusätzlich als
     * Zeichenkette in `events.type`. Die Spalte wird beim Anlegen und Bearbeiten
     * eines Termins mitgeschrieben - beim Umbenennen der Terminart dagegen
     * nicht, und das trägt niemand nach. Wer sie liest, zeigt deshalb nach
     * einer Umbenennung den alten Namen.
     *
     * Genau so stand es auf der Anwesenheitsseite: Terminauswahl und Kopfzeile
     * lasen `events.type`, während Terminliste und Kalender die Beziehung
     * nahmen - dieselbe Probe stand dort als "Gesamtprobe" und hier noch als
     * "Probe".
     *
     * Die Regel steht hier und nicht in jeder Ansicht: Sie stand bis hierher
     * nur im EventController, und die zweite Stelle, die denselben Namen
     * brauchte, nahm die Spalte. Der Rückfall bleibt die Spalte, denn
     * `event_type_id` darf leer sein, und ohne sie stände in der Kopfzeile
     * sonst nichts.
     *
     * Die Beziehung sollte vorab geladen sein (`with('eventType')` oder
     * `setRelation()`), sonst holt Eloquent sie je Termin nach. Falsch wird die
     * Antwort dadurch nicht, nur teuer.
     */
    public function typeName(): string
    {
        return $this->eventType->name ?? (string) $this->type;
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
