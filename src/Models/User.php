<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class User extends Model
{
    protected $table = 'users';
    public $timestamps = false;

    protected $fillable = [
        'email',
        'password',
        'first_name',
        'last_name',
        'is_active'
    ];

    public function getPasswordAttribute()
    {
        return $this->attributes['password'] ?? null;
    }

    public function setPasswordAttribute($value)
    {
        $this->attributes['password'] = $value;
    }

    public function roles()
    {
        return $this->belongsToMany(Role::class, 'user_roles', 'user_id', 'role_id');
    }

    public function projects()
    {
        return $this->belongsToMany(Project::class, 'project_users', 'user_id', 'project_id');
    }

    public function voiceGroups()
    {
        return $this->belongsToMany(VoiceGroup::class, 'user_voice_groups', 'user_id', 'voice_group_id')
            ->withPivot('sub_voice_id');
    }

    public function subVoices()
    {
        return $this->belongsToMany(SubVoice::class, 'user_voice_groups', 'user_id', 'sub_voice_id')
            ->withPivot('voice_group_id');
    }

    public function attendances()
    {
        return $this->hasMany(Attendance::class, 'user_id', 'id');
    }

    public function newsletterRecipients()
    {
        return $this->hasMany(NewsletterRecipient::class, 'user_id', 'id');
    }
}
