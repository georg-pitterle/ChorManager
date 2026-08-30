<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * Merkzettel der termingesteuerten Erinnerungen. Der eindeutige Index aus
 * Migration 20260830141000 hält jede Kombination genau einmal fest.
 */
class NotificationDispatchLog extends Model
{
    protected $table = 'notification_dispatch_log';

    public $timestamps = false;

    protected $fillable = [
        'notification_type',
        'entity_type',
        'entity_id',
        'user_id',
        'dispatch_key',
        'created_at',
    ];

    protected $casts = [
        'created_at' => 'datetime',
    ];
}
