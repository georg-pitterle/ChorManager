<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Role extends Model
{
    protected $table = 'roles';
    public $timestamps = false;

    protected $fillable = [
        'name',
        'hierarchy_level',
        'can_manage_users',
        'can_edit_users',
        'can_manage_project_members',
        'can_manage_finances',
        'can_manage_master_data',
        'can_manage_sponsoring',
        'can_manage_song_library',
    ];

    public function users()
    {
        return $this->belongsToMany(User::class, 'user_roles', 'role_id', 'user_id');
    }
}
