<?php

declare(strict_types=1);

namespace App\Models;

use App\Models\Concerns\HasEntityAttachments;
use App\Models\Concerns\HasEntityComments;
use Illuminate\Database\Eloquent\Model;

class Project extends Model
{
    use HasEntityAttachments;
    use HasEntityComments;

    /** Der Wert, unter dem Notizen und Anhänge an diesem Projekt hängen. */
    public const ENTITY_TYPE = 'project';

    protected $table = 'projects';
    public $timestamps = false; // Add created_at/updated_at to schema if true

    protected $fillable = [
        'name',
        'description',
        'start_date',
        'end_date',
        'logo_deadline_print',
        'logo_deadline_online',
    ];

    protected $casts = [
        'start_date' => 'date',
        'end_date' => 'date',
        'logo_deadline_print' => 'date',
        'logo_deadline_online' => 'date',
    ];

    /**
     * Gemeinsame Reihenfolge aller Projektlisten: das zuletzt gestartete Projekt
     * oben, damit das laufende dort steht, wo gesucht wird. Projekte ohne
     * Startdatum landen von selbst am Ende - NULL ist der kleinste Wert und bei
     * absteigender Sortierung damit der letzte. Der Name entscheidet bei gleichem
     * Datum, sonst wechselte die Reihenfolge zwischen zwei Aufrufen.
     *
     * Die Spalten sind qualifiziert, weil Aufrufer über project_users joinen.
     *
     * Liegt am Model und nicht nur in ProjectQuery, damit auch Abfragen mit
     * eigenen Bedingungen dieselbe Reihenfolge bekommen, ohne sie abzuschreiben.
     *
     * @param \Illuminate\Database\Eloquent\Builder<Project> $query
     * @return \Illuminate\Database\Eloquent\Builder<Project>
     */
    public function scopeChronological($query)
    {
        return $query
            ->orderBy('projects.start_date', 'desc')
            ->orderBy('projects.name');
    }

    public function users()
    {
        return $this->belongsToMany(User::class, 'project_users', 'project_id', 'user_id');
    }

    /**
     * Termine, deren Zielgruppe dieses Projekt in mindestens einer Zeile nennt.
     * Liefert einen Query Builder - ein Termin hängt nicht per Fremdschlüssel an
     * einem Projekt, die Verbindung läuft über die Zielgruppen-Filter.
     *
     * Heißt bewusst `eventsQuery()` und nicht `events()`: Eine parameterlose
     * `events()` hält Eloquent beim Property-Zugriff für eine Relation und
     * wirft "must return a relationship instance", sobald jemand `project.events`
     * schreibt - und genau das schreibt ein Template, ohne zu ahnen, dass hier
     * keine Relation steht. Gleiche Begründung wie bei
     * Finance::getIsReversalAttribute().
     */
    public function eventsQuery()
    {
        return Event::query()->forProject((int) $this->id);
    }

    /**
     * Lieder des Projekts. Die Zuordnung läuft ausschließlich über
     * project_song_assignments; `songs.project_id` gibt es seit
     * 20260421120000_drop_songs_project_id nicht mehr.
     */
    public function assignedSongs()
    {
        return $this->belongsToMany(
            Song::class,
            'project_song_assignments',
            'project_id',
            'song_id'
        )->withPivot('note', 'created_at');
    }

    public function tasks()
    {
        return $this->hasMany(Task::class, 'project_id', 'id');
    }
}
