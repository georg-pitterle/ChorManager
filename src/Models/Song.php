<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Song extends Model
{
    protected $table = 'songs';
    public $timestamps = false;

    protected $fillable = [
        'title',
        'composer',
        'arranger',
        'publisher',
        'created_by_user_id',
    ];

    public function attachments()
    {
        return $this->hasMany(Attachment::class, 'entity_id', 'id')->where('entity_type', 'song');
    }

    public function categories()
    {
        return $this->belongsToMany(
            Category::class,
            'song_category_assignments',
            'song_id',
            'repertoire_category_id'
        );
    }

    /**
     * Einziger Weg vom Lied zum Projekt. Eine direkte belongsTo-Relation gibt es
     * nicht mehr: `songs.project_id` ist mit
     * 20260421120000_drop_songs_project_id entfallen.
     */
    public function projectAssignments()
    {
        return $this->hasMany(ProjectSongAssignment::class, 'song_id', 'id');
    }

    public function resources()
    {
        return $this->hasMany(SongResource::class, 'song_id', 'id');
    }

    public function linkResources()
    {
        return $this->hasMany(SongResource::class, 'song_id', 'id')
            ->where('resource_type', 'link')
            ->orderBy('title', 'asc');
    }

    public function sheetArchive()
    {
        return $this->hasOne(SheetArchive::class, 'song_id', 'id');
    }
}
