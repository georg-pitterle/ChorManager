<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class ProjectSongAssignment extends Model
{
    protected $table = 'project_song_assignments';
    public $timestamps = false;

    protected $fillable = [
        'project_id',
        'song_id',
        'note',
    ];

    public function song()
    {
        return $this->belongsTo(Song::class, 'song_id', 'id');
    }

    public function project()
    {
        return $this->belongsTo(Project::class, 'project_id', 'id');
    }
}
