<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class InvitationToken extends Model
{
    protected $table = 'invitation_tokens';
    public $timestamps = false;

    protected $fillable = [
        'user_id',
        'selector',
        'token_hash',
        'expires_at',
        'created_at',
    ];

    /**
     * Der Hash ist das Geheimnis der Einladung; er darf nicht mitserialisiert
     * werden. Der `selector` bleibt sichtbar - er ist reiner Nachschlagewert.
     *
     * @var list<string>
     */
    protected $hidden = [
        'token_hash',
    ];

    protected $casts = [
        'expires_at' => 'datetime',
        'created_at' => 'datetime',
    ];

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
