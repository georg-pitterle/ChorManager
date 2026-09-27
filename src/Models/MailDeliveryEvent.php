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

    /**
     * `raw_payload` ist der unveränderte Rumpf, den der Provider an den Webhook
     * geschickt hat - fremder Text von einem offenen Endpunkt, in beliebiger Länge
     * und mit beliebigem Inhalt. Gleiche Grenze und gleiche Begründung wie bei
     * `MailQueue::$payload_json`: Was über die Serialisierung in eine JSON-Antwort
     * oder eine Logzeile gerät, umgeht die Rechteprüfung davor, und ein ganzer
     * Webhook-Rumpf sprengt jede Zeile im Container-Log.
     *
     * Auf `$event->raw_payload` wirkt sich das nicht aus - wer den Rumpf zur
     * Fehlersuche braucht, liest die Eigenschaft direkt.
     *
     * @var list<string>
     */
    protected $hidden = [
        'raw_payload',
    ];

    protected $casts = [
        'mail_queue_id' => 'integer',
        'occurred_at' => 'datetime',
        'received_at' => 'datetime',
    ];

    public $timestamps = false;
}
