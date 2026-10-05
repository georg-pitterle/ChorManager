<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * Zugangstoken, mit dem Collabora eine Datei für eine Person holt und speichert.
 * Der Klartext steht nur in der Editor-Seite, hier liegt sein SHA-256.
 */
class OfficeAccessToken extends Model
{
    protected $table = 'office_access_tokens';

    public $timestamps = false;

    protected $fillable = ['token_hash', 'file_id', 'user_id', 'expires_at', 'created_at'];

    /** Der Hash ersetzt kein Token, gehört aber trotzdem in keine Serialisierung. */
    protected $hidden = ['token_hash'];

    protected $casts = [
        'file_id' => 'integer',
        'user_id' => 'integer',
        'expires_at' => 'datetime',
        'created_at' => 'datetime',
    ];
}
