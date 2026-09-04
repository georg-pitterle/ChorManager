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

    /**
     * Notizen der Aufgabe. Private Notizen bleiben hier aussen vor - gleiche
     * Grenze und gleiche Begründung wie bei Event::comments(): Die Relation kennt
     * die angemeldete Person nicht und könnte eine fremde private Notiz nicht von
     * der eigenen unterscheiden. Ohne die Grenze hinge es am Aufrufer, ob eine
     * fremde private Notiz in der Ausgabe landet - und `withCount('comments')`
     * in der Aufgabenliste hätte sie sogar mitgerechnet.
     *
     * Wer auch die eigenen privaten Notizen braucht, setzt die Relation mit
     * Comment::visibleTo() bewusst selbst.
     */
    public function comments()
    {
        return $this->hasMany(Comment::class, 'entity_id')
            ->where('entity_type', 'task')
            ->where('is_private', false);
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
