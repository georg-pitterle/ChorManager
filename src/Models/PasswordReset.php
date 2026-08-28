<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class PasswordReset extends Model
{
    protected $table = 'password_resets';
    public $timestamps = false; // We use created_at, no updated_at
    protected $primaryKey = 'email';
    public $incrementing = false;
    protected $keyType = 'string';

    protected $fillable = [
        'email',
        'token',
        'created_at'
    ];

    /**
     * Der Token ist der Zurücksetzen-Link selbst und darf nicht mitserialisiert
     * werden. Der Abgleich in PasswordResetController liest die Eigenschaft
     * direkt und bleibt davon unberührt.
     *
     * @var list<string>
     */
    protected $hidden = [
        'token',
    ];

    protected $casts = [
        'created_at' => 'datetime',
    ];
}
