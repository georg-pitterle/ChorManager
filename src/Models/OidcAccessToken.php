<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * Ein Zugriffstoken für /oidc/userinfo. Kurzlebig und widerrufbar.
 *
 * Gespeichert wird nur der SHA-256-Hash. `auth_code_id` bleibt erhalten, damit
 * sich bei einer zweiten Einlösung desselben Codes genau die daraus
 * ausgestellten Token widerrufen lassen.
 */
class OidcAccessToken extends Model
{
    protected $table = 'oidc_access_tokens';
    public $timestamps = false;

    protected $fillable = [
        'token_hash',
        'client_id',
        'user_id',
        'auth_code_id',
        'scope',
        'expires_at',
        'revoked_at',
        'created_at',
    ];

    /** @var list<string> */
    protected $hidden = [
        'token_hash',
    ];

    protected $casts = [
        'user_id' => 'integer',
        'auth_code_id' => 'integer',
        'expires_at' => 'datetime',
        'revoked_at' => 'datetime',
        'created_at' => 'datetime',
    ];

    public function user()
    {
        return $this->belongsTo(User::class, 'user_id', 'id');
    }
}
