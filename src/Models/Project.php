<?php
declare(strict_types = 1)
;

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
        return $this->belongsToMany(User::class , 'project_users', 'project_id', 'user_id');
    }

    public function events()
    {
        return $this->hasMany(Event::class , 'project_id', 'id');
    }
}
