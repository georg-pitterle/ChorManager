<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * Ein Autorisierungscode: der kurzlebige Beleg, dass sich jemand soeben in
 * ChorManager angemeldet hat und die fremde Anwendung dafür Token abholen darf.
 *
 * Gespeichert wird nur der SHA-256-Hash des Zufallswerts, wie bei
 * calendar_subscription_tokens. Eingelöst wird genau einmal - `used_at` bleibt
 * danach stehen, statt die Zeile zu löschen, damit eine zweite Einlösung als
 * das erkennbar ist, was sie ist: ein Diebstahlsverdacht.
 */
class OidcAuthCode extends Model
{
    protected $table = 'oidc_auth_codes';
    public $timestamps = false;

    protected $fillable = [
        'code_hash',
        'client_id',
        'user_id',
        'redirect_uri',
        'scope',
        'nonce',
        'code_challenge',
        'code_challenge_method',
        'expires_at',
        'used_at',
        'created_at',
    ];

    /** @var list<string> */
    protected $hidden = [
        'code_hash',
        'code_challenge',
    ];

    protected $casts = [
        'user_id' => 'integer',
        'expires_at' => 'datetime',
        'used_at' => 'datetime',
        'created_at' => 'datetime',
    ];

    public function user()
    {
        return $this->belongsTo(User::class, 'user_id', 'id');
    }
}
