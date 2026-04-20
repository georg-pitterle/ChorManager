<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

final class MailDeliveryEvent extends Model
{
    protected $table = 'mail_delivery_events';

    protected $fillable = [
        'mail_queue_id',
        'provider_name',
        'provider_message_id',
        'source_channel',
        'event_type_normalized',
        'event_type_raw',
        'idempotency_key',
        'occurred_at',
        'received_at',
        'raw_payload',
    ];

    protected $casts = [
        'mail_queue_id' => 'integer',
        'occurred_at' => 'datetime',
        'received_at' => 'datetime',
    ];

    public $timestamps = false;
}
