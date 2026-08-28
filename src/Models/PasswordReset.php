<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class PasswordReset extends Model
{
    protected $table = 'password_resets';
    public $timestamps = false; // We use created_at, no updated_at

    /*
     * Primaerschluessel ist die Auto-Increment-Spalte id, so wie die Tabelle sie
     * fuehrt. Frueher stand hier email mit $incrementing = false; damit blieb
     * $model->id nach create() leer. Aufgefallen war es nie, weil jeder Zugriff
     * ueber where('email', ...) laeuft - email ist UNIQUE und bleibt es.
     */

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
