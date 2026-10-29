<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class PasswordReset extends Model
{
    protected $table = 'password_resets';
    public $timestamps = false; // We use created_at, no updated_at

    /*
     * Primärschlüssel ist die Auto-Increment-Spalte id, so wie die Tabelle sie
     * führt. Früher stand hier email mit $incrementing = false; damit blieb
     * $model->id nach create() leer. Aufgefallen war es nie, weil jeder Zugriff
     * über where('email', ...) läuft - email ist UNIQUE und bleibt es.
     */

    protected $fillable = [
        'email',
        'token',
        'created_at'
    ];

    /**
     * In der Spalte steht nicht der Token des Zurücksetzen-Links, sondern sein
     * bcrypt-Hash (`PasswordHasher::hash()` beim Anlegen,
     * `password_verify()` beim Einlösen) - der Klartext verlässt die Mail nie.
     * Der Hash taugt aber zum Abgleich gegen einen geratenen Token und hat
     * deshalb in Logs, Fehlerausgaben und JSON-Antworten nichts verloren;
     * gleiche Grenze wie bei `InvitationToken::$hidden`.
     *
     * Der Abgleich in PasswordResetController liest die Eigenschaft direkt und
     * bleibt davon unberührt.
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
