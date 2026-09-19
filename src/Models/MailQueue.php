<?php

declare(strict_types=1);

namespace App\Models;

use Carbon\Carbon;
use Illuminate\Database\Eloquent\Model;

class MailQueue extends Model
{
    /**
     * Mailarten, deren fertiger Text ein Geheimnis trägt: Der Einmal-Link einer
     * Passwort-Zurücksetzung und einer Einladung steht ausgeschrieben im HTML.
     * Nach der Zustellung hat er in der Warteschlange nichts mehr verloren -
     * sonst reicht das Recht "Mail-Warteschlange verwalten" aus, um über die
     * Einzelansicht jedes Konto zu übernehmen, auch eines mit mehr Rechten.
     *
     * Gelöscht wird erst nach erfolgreichem Versand, nie vorher: Ein Eintrag,
     * der noch zugestellt werden muss, braucht seinen Text.
     *
     * @var list<string>
     */
    public const SECRET_BODY_MAIL_TYPES = [
        'password_reset',
        'invitation',
    ];

    /**
     * Spalten, die die Listenansicht der Warteschlange laden darf.
     *
     * `body_html` ist longtext und `payload_json` text; die Liste zeigt beides
     * nicht an, las es aber für bis zu 200 Zeilen je Seite mit. Nach einem
     * Newsletter-Versand summierte sich das je Seitenaufruf.
     *
     * Wer eine weitere Spalte in der Liste braucht, nimmt sie hier auf - nicht
     * in einen eigenen select() an der Aufrufstelle, sonst fällt die Grenze
     * wieder auseinander. Gleiches Muster wie `User::LIST_COLUMNS`.
     *
     * @var list<string>
     */
    public const LIST_COLUMNS = [
        'id',
        'mail_type',
        'recipient_email',
        'status',
        'attempts',
        'max_attempts',
        'next_attempt_at',
        'error_code',
        'error_message',
        'created_at',
    ];

    public $timestamps = true;

    protected $table = 'mail_queue';

    protected $fillable = [
        'mail_type',
        'recipient_email',
        'subject',
        'body_html',
        'payload_json',
        'status',
        'delivery_status',
        'provider_name',
        'provider_message_id',
        'attempts',
        'max_attempts',
        'next_attempt_at',
        'last_attempt_at',
        'sent_at',
        'accepted_at',
        'delivered_at',
        'bounced_at',
        'complained_at',
        'last_event_at',
        'last_event_type',
        'error_code',
        'error_message',
        'is_retryable',
    ];

    /**
     * Zweite Absicherung neben LIST_COLUMNS: Wird ein Eintrag doch einmal mit
     * allen Spalten geladen und danach serialisiert, bleiben Mailtext und
     * Nutzdaten aus der Ausgabe. Gleiche Begründung wie bei
     * `Attachment::$hidden` - was über die Serialisierung in eine JSON-Antwort
     * oder eine Logzeile gerät, umgeht die Rechteprüfung davor.
     *
     * Auf `$entry->body_html` wirkt sich das nicht aus: Die Einzelansicht und
     * MailDeliveryService lesen die Eigenschaft direkt.
     *
     * @var list<string>
     */
    protected $hidden = [
        'body_html',
        'payload_json',
    ];

    protected $casts = [
        'is_retryable' => 'boolean',
        'created_at' => 'datetime',
        'updated_at' => 'datetime',
        'next_attempt_at' => 'datetime',
        'last_attempt_at' => 'datetime',
        'sent_at' => 'datetime',
        'accepted_at' => 'datetime',
        'delivered_at' => 'datetime',
        'bounced_at' => 'datetime',
        'complained_at' => 'datetime',
        'last_event_at' => 'datetime',
        'payload_json' => 'array',
    ];

    // Scopes
    public function scopeQueued($query)
    {
        return $query->where('status', 'queued');
    }

    public function scopeFailed($query)
    {
        return $query->where('status', 'failed');
    }

    public function scopeDead($query)
    {
        return $query->where('status', 'dead');
    }

    public function scopeSent($query)
    {
        return $query->where('status', 'sent');
    }

    public function scopeDueSoon($query)
    {
        return $query->where(function ($statusScopedQuery) {
            $statusScopedQuery->where('status', 'queued')
                ->orWhere(function ($retryableFailed) {
                    $retryableFailed->where('status', 'failed')
                        ->where('is_retryable', true)
                        ->whereColumn('attempts', '<', 'max_attempts');
                });
        })->where(function ($q) {
            $q->whereNull('next_attempt_at')
                ->orWhere('next_attempt_at', '<=', Carbon::now());
        });
    }

    // Helpers
    public function isDelivered(): bool
    {
        return $this->delivery_status === 'delivered';
    }

    public function isDeadLetter(): bool
    {
        return $this->status === 'dead';
    }

    /**
     * Trägt der Mailtext dieses Eintrags ein Geheimnis, das nach der Zustellung
     * verschwinden muss?
     */
    public function bodyHoldsSecret(): bool
    {
        return in_array((string) $this->mail_type, self::SECRET_BODY_MAIL_TYPES, true);
    }

    public function canRetry(): bool
    {
        if ($this->status === 'dead') {
            return true;
        }

        if ($this->status !== 'failed') {
            return false;
        }

        return $this->is_retryable && $this->attempts < $this->max_attempts;
    }
}
