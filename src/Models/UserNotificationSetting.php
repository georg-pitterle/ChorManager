<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * Die abweichende Entscheidung einer Person zu einem Benachrichtigungs-Anlass.
 *
 * Fehlt die Zeile, gilt die Vorgabe aus `NotificationType` - siehe die
 * Begründung in Migration 20260830140000.
 */
class UserNotificationSetting extends Model
{
    protected $table = 'user_notification_settings';

    public $incrementing = false;
    public $timestamps = false;

    protected $fillable = [
        'user_id',
        'notification_type',
        'enabled',
    ];

    protected $casts = [
        'enabled' => 'boolean',
    ];

    public function user()
    {
        return $this->belongsTo(User::class, 'user_id');
    }
}
