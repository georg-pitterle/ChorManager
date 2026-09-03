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
        'token_hash',
        'created_at',
    ];

    /**
     * Der Hash ist zwar nicht die Abo-Adresse selbst, taugt aber zum Abgleich
     * gegen eine geratene - er hat in Logs, Fehlerausgaben und JSON-Antworten
     * nichts verloren. Der Klartext steht seit 20260901122000 nirgends mehr.
     *
     * @var list<string>
     */
    protected $hidden = [
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
