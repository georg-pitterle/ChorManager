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

    public function users()
    {
        return $this->belongsToMany(User::class, 'project_users', 'project_id', 'user_id');
    }

    public function events()
    {
        return $this->hasMany(Event::class, 'project_id', 'id');
    }

    public function songs()
    {
        return $this->hasMany(Song::class, 'project_id', 'id');
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
