<?php

declare(strict_types=1);

namespace App\Models;

use Carbon\Carbon;
use Illuminate\Database\Eloquent\Model;

class MailQueue extends Model
{
    /**
     * Zustand eines Eintrags in der Warteschlange, in der Reihenfolge, die er
     * durchläuft: `queued` beim Einstellen, `sending` während eines Versuchs
     * (atomar gesetzt, damit kein zweiter Lauf denselben Eintrag greift), dann
     * `sent`, `skipped` (bewusst nicht verschickt, etwa ohne Adresse),
     * `failed` (ein Versuch offen) oder `dead` (endgültig liegengeblieben).
     *
     * Die Werte spiegeln das ENUM aus Migration 20260419130000 und standen
     * vorher nur als Zeichenketten in den Scopes hier, in MailQueueService,
     * MailDeliveryService und MailQueueAdminService.
     */
    public const STATUS_QUEUED = 'queued';
    public const STATUS_SENDING = 'sending';
    public const STATUS_SENT = 'sent';
    public const STATUS_SKIPPED = 'skipped';
    public const STATUS_FAILED = 'failed';
    public const STATUS_DEAD = 'dead';

    /** @var list<string> */
    public const SUPPORTED_STATUSES = [
        self::STATUS_QUEUED,
        self::STATUS_SENDING,
        self::STATUS_SENT,
        self::STATUS_SKIPPED,
        self::STATUS_FAILED,
        self::STATUS_DEAD,
    ];

    /**
     * Was der Versanddienstleister über die Zustellung zurückmeldet - eine
     * andere Frage als `status`, der nur sagt, ob wir die Mail losgeschickt
     * haben. `accepted` heißt angenommen, nicht angekommen; erst `delivered`,
     * `bounced` oder `complained` kommt aus dem Webhook.
     *
     * ENUM aus Migration 20260420110000.
     */
    public const DELIVERY_STATUS_PENDING = 'pending';
    public const DELIVERY_STATUS_ACCEPTED = 'accepted';
    public const DELIVERY_STATUS_DELIVERED = 'delivered';
    public const DELIVERY_STATUS_BOUNCED = 'bounced';
    public const DELIVERY_STATUS_COMPLAINED = 'complained';
    public const DELIVERY_STATUS_SKIPPED = 'skipped';

    /** @var list<string> */
    public const DELIVERY_STATUSES = [
        self::DELIVERY_STATUS_PENDING,
        self::DELIVERY_STATUS_ACCEPTED,
        self::DELIVERY_STATUS_DELIVERED,
        self::DELIVERY_STATUS_BOUNCED,
        self::DELIVERY_STATUS_COMPLAINED,
        self::DELIVERY_STATUS_SKIPPED,
    ];

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
    public function scopeDead($query)
    {
        return $query->where('status', self::STATUS_DEAD);
    }

    public function scopeDueSoon($query)
    {
        return $query->where(function ($statusScopedQuery) {
            $statusScopedQuery->where('status', self::STATUS_QUEUED)
                ->orWhere(function ($retryableFailed) {
                    $retryableFailed->where('status', self::STATUS_FAILED)
                        ->where('is_retryable', true)
                        ->whereColumn('attempts', '<', 'max_attempts');
                });
        })->where(function ($q) {
            $q->whereNull('next_attempt_at')
                ->orWhere('next_attempt_at', '<=', Carbon::now());
        });
    }

    // Helpers
    /**
     * Trägt der Mailtext dieses Eintrags ein Geheimnis, das nach der Zustellung
     * verschwinden muss?
     */
    public function bodyHoldsSecret(): bool
    {
        return in_array((string) $this->mail_type, self::SECRET_BODY_MAIL_TYPES, true);
    }

    /**
     * Darf dieser Eintrag von Hand erneut in die Warteschlange gestellt werden?
     *
     * Nur ein endgültig liegengebliebener - STATUS_DEAD. Ein `failed`-Eintrag,
     * der noch Versuche frei hat, ist kein Fall für den Handknopf: Ihn holt sich
     * `scopeDueSoon()` von selbst wieder, und ein Zurücksetzen von Hand würde
     * dabei nur den Zähler verlieren.
     *
     * Vorher bejahte diese Methode genau diesen Fall und widersprach damit
     * `MailQueueAdminService::retrySingle()`, das ihn abweist ("Only dead entries
     * can be retried"). Die beiden Antworten liefen auseinander, weil die
     * Verwaltung ihre eigene Bedingung mitbrachte statt zu fragen; sie fragt
     * jetzt hier.
     */
    public function canRetry(): bool
    {
        return $this->status === self::STATUS_DEAD;
    }
}
