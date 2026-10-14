<?php

declare(strict_types=1);

namespace App\Models;

use App\Models\Concerns\HasEntityAttachments;
use App\Models\Concerns\HasEntityComments;
use Illuminate\Database\Eloquent\Model;

class Task extends Model
{
    use HasEntityAttachments;
    use HasEntityComments;

    /** Der Wert, unter dem Notizen, Anhänge und Verlauf an dieser Aufgabe hängen. */
    public const ENTITY_TYPE = 'task';

    protected $table = 'tasks';
    public $timestamps = false;

    protected $fillable = [
        'project_id',
        'name',
        'description',
        'start_date',
        'end_date',
        'status',
        'priority',
        'created_by',
        'created_at',
        'updated_at'
    ];

    protected $casts = [
        'start_date' => 'date',
        'end_date' => 'date',
        'created_at' => 'datetime',
        'updated_at' => 'datetime',
    ];

    public function project()
    {
        return $this->belongsTo(Project::class, 'project_id');
    }

    /**
     * Zugewiesene Personen. Eine Aufgabe kann mehreren gehören - bis
     * 20260830120000 hielt `tasks.assigned_to` genau eine, und wer zu zweit an
     * etwas arbeitete, legte die Aufgabe doppelt an.
     */
    public function assignees()
    {
        return $this->belongsToMany(User::class, 'task_assignees', 'task_id', 'user_id');
    }

    public function createdBy()
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function activities()
    {
        return $this->hasMany(Activity::class, 'entity_id')
            ->where('entity_type', self::ENTITY_TYPE)
            ->orderBy('created_at', 'desc');
    }
}
