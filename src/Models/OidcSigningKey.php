<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * Ein RSA-Schlüsselpaar für die Signatur der Anmeldezeugnisse.
 *
 * Der öffentliche Teil geht über /oidc/jwks.json hinaus, der private liegt
 * verschlüsselt daneben (libsodium-Secretbox, Schlüssel aus
 * OIDC_SIGNING_KEY_SECRET). Signiert wird immer mit dem aktiven Schlüssel, in
 * JWKS stehen auch die abgelösten - sonst bräche ein beim Client
 * zwischengespeicherter Schlüsselsatz im Augenblick der Rotation.
 */
class OidcSigningKey extends Model
{
    protected $table = 'oidc_signing_keys';
    public $timestamps = false;

    protected $fillable = [
        'kid',
        'public_key',
        'private_key_encrypted',
        'is_active',
        'created_at',
    ];

    /** @var list<string> */
    protected $hidden = [
        'private_key_encrypted',
    ];

    protected $casts = [
        'is_active' => 'boolean',
        'created_at' => 'datetime',
    ];
}
