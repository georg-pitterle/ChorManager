<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Project extends Model
{
    protected $table = 'projects';
    public $timestamps = false; // Add created_at/updated_at to schema if true

    protected $fillable = [
        'name',
        'description',
        'start_date',
        'end_date'
    ];

    protected $casts = [
        'start_date' => 'date',
        'end_date' => 'date',
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
     * Events targeting this project via a project_members audience source.
     * Returns a query builder (events are no longer bound to a project by a
     * foreign key; the link now runs through event_audience_sources).
     */
    public function events()
    {
        return Event::query()
            ->whereHas('audienceSources', function ($sourceQuery) {
                $sourceQuery->where('source_type', EventAudienceSource::TYPE_PROJECT_MEMBERS)
                    ->where('reference_id', (int) $this->id);
            });
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

    public function comments()
    {
        return $this->hasMany(Comment::class, 'entity_id', 'id')->where('entity_type', 'project');
    }

    public function attachments()
    {
        return $this->hasMany(Attachment::class, 'entity_id', 'id')->where('entity_type', 'project');
    }
}
