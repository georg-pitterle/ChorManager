<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class WebdavAccessToken extends Model
{
    protected $table = 'webdav_access_tokens';
    public $timestamps = false;

    protected $fillable = [
        'user_id',
        'token_hash',
        'created_at',
        'last_used_at',
    ];

    /**
     * Der Hash taugt zum Abgleich gegen einen geratenen Token und hat deshalb in
     * Logs, Fehlerausgaben und JSON-Antworten nichts verloren - gleiche Grenze
     * wie bei CalendarSubscriptionToken.
     *
     * @var list<string>
     */
    protected $hidden = [
        'token_hash',
    ];

    protected $casts = [
        'created_at' => 'datetime',
        'last_used_at' => 'datetime',
    ];

    public function user()
    {
        return $this->belongsTo(User::class, 'user_id', 'id');
    }
}
