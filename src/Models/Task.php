<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Task extends Model
{
    protected $table = 'tasks';
    public $timestamps = false;

    protected $fillable = [
        'project_id',
        'name',
        'description',
        'assigned_to',
        'start_date',
        'end_date',
        'status',
        'priority',
        'created_by',
        'created_at',
        'updated_at'
    ];

    public function project()
    {
        return $this->belongsTo(Project::class, 'project_id');
    }

    public function assignee()
    {
        return $this->belongsTo(User::class, 'assigned_to');
    }

    public function createdBy()
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function comments()
    {
        return $this->hasMany(Comment::class, 'entity_id')->where('entity_type', 'task');
    }

    public function attachments()
    {
        return $this->hasMany(Attachment::class, 'entity_id')->where('entity_type', 'task');
    }

    public function activities()
    {
        return $this->hasMany(Activity::class, 'entity_id')->where('entity_type', 'task')->orderBy('created_at', 'desc');
    }
}
