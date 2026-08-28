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
