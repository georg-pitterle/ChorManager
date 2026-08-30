<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class User extends Model
{
    protected $table = 'users';
    public $timestamps = false;

    /**
     * Spalten, die Listenabfragen laden dürfen. Solche Abfragen reichen ihre
     * Modelle unverändert an die View-Schicht durch; der Passwort-Hash hat dort
     * nichts verloren, `last_project_id` ist reiner Sitzungszustand.
     *
     * `id` muss enthalten bleiben, sonst lassen sich die Relationen nicht zuordnen.
     *
     * @var list<string>
     */
    public const LIST_COLUMNS = [
        'id',
        'email',
        'first_name',
        'last_name',
        'is_active',
    ];

    protected $fillable = [
        'email',
        'password',
        'first_name',
        'last_name',
        'is_active'
    ];

    /**
     * Zweite Absicherung neben LIST_COLUMNS: Wo ein Nutzer doch einmal mit allen
     * Spalten geladen und danach serialisiert wird, bleibt der Passwort-Hash aus
     * der Ausgabe. Auf `$user->password` wirkt sich das nicht aus - der
     * Login-Abgleich liest die Eigenschaft direkt.
     *
     * @var list<string>
     */
    protected $hidden = [
        'password',
    ];

    /**
     * `is_active` ist tinyint(1) und kam ohne Cast als 1/0 zurück. Templates
     * prüfen die Spalte auf Wahrheitswert, das bleibt unverändert; neuer Code
     * mit `=== true` liest jetzt aber das, was dasteht.
     *
     * @var array<string, string>
     */
    protected $casts = [
        'is_active' => 'boolean',
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
            ->withPivot('sub_voice_id')
            ->orderBy('voice_groups.id');
    }

    public function subVoices()
    {
        return $this->belongsToMany(SubVoice::class, 'user_voice_groups', 'user_id', 'sub_voice_id')
            ->withPivot('voice_group_id')
            ->orderBy('sub_voices.name');
    }

    public function attendances()
    {
        return $this->hasMany(Attendance::class, 'user_id', 'id');
    }

    public function eventRegistrations()
    {
        return $this->hasMany(EventRegistration::class, 'user_id', 'id');
    }

    public function newsletterRecipients()
    {
        return $this->hasMany(NewsletterRecipient::class, 'user_id', 'id');
    }

    public function tasks()
    {
        return $this->belongsToMany(Task::class, 'task_assignees', 'user_id', 'task_id');
    }

    public function comments()
    {
        return $this->hasMany(Comment::class, 'user_id', 'id');
    }

    public function activities()
    {
        return $this->hasMany(Activity::class, 'user_id', 'id')->orderBy('created_at', 'desc');
    }

    public function mailAccount()
    {
        return $this->hasOne(UserMailAccount::class, 'user_id', 'id');
    }
}
