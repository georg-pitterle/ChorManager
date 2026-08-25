<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class CalendarSubscriptionToken extends Model
{
    protected $table = 'calendar_subscription_tokens';
    public $timestamps = false;

    protected $fillable = [
        'user_id',
        'token',
        'token_hash',
        'created_at',
    ];

    /**
     * Der Klartext-Token darf nie in Logs, Fehlerausgaben oder JSON-Antworten
     * landen; er ist die Abo-Adresse selbst. Neue Zeilen tragen ihn ohnehin nicht
     * mehr, für Altbestände sperrt das hier die versehentliche Ausgabe.
     *
     * @var list<string>
     */
    protected $hidden = [
        'token',
        'token_hash',
    ];

    protected $casts = [
        'created_at' => 'datetime',
    ];

    public function user()
    {
        return $this->belongsTo(User::class, 'user_id', 'id');
    }
}
