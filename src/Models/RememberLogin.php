<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class RememberLogin extends Model
{
    protected $table = 'remember_logins';
    public $timestamps = false;

    protected $fillable = [
        'user_id',
        'selector',
        'token_hash',
        'expires_at',
        'created_at',
        'last_used_at',
        'user_agent',
        'ip_address'
    ];

    /**
     * Der Hash ist das Geheimnis der Anmeldung "Angemeldet bleiben" und hat in
     * Logs, Fehlerausgaben oder JSON-Antworten nichts verloren. Der `selector`
     * bleibt sichtbar - er ist reiner Nachschlagewert.
     *
     * @var list<string>
     */
    protected $hidden = [
        'token_hash',
    ];

    protected $casts = [
        'expires_at' => 'datetime',
        'created_at' => 'datetime',
        'last_used_at' => 'datetime',
    ];
}
