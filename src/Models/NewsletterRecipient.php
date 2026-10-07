<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class NewsletterRecipient extends Model
{
    /**
     * Zustand einer Zustellung an genau eine Person, in der Reihenfolge, die
     * sie durchläuft: `pending` beim Anlegen der Empfängerliste, `queued`, wenn
     * NewsletterService die Mail in die Warteschlange gelegt hat, und `sent`
     * bzw. `failed`, sobald MailDeliveryService das Ergebnis der Zustellung
     * zurückmeldet.
     *
     * `queued` fehlte hier, obwohl Migration 20260419223000 es in das ENUM
     * aufgenommen hat und der Versand es seitdem schreibt: Der normale Zustand
     * direkt nach dem Versand fiel damit durch alle drei Prüfmethoden.
     *
     * Die Werte standen vorher nur als Zeichenketten in den Prüfmethoden hier
     * und in den Schreibstellen in NewsletterService,
     * NewsletterRecipientService und MailDeliveryService - ein Tippfehler an
     * einer davon wäre still geblieben, denn das ENUM weist einen unbekannten
     * Wert nicht in jedem Modus ab. Gleiches Muster wie Newsletter::STATUS_*
     * und Attendance::STATUS_*.
     */
    public const STATUS_PENDING = 'pending';
    public const STATUS_QUEUED = 'queued';
    public const STATUS_SENT = 'sent';
    public const STATUS_FAILED = 'failed';

    /** @var list<string> */
    public const SUPPORTED_STATUSES = [
        self::STATUS_PENDING,
        self::STATUS_QUEUED,
        self::STATUS_SENT,
        self::STATUS_FAILED,
    ];

    protected $table = 'newsletter_recipients';
    public $timestamps = false;

    protected $fillable = [
        'newsletter_id',
        'user_id',
        'status',
    ];

    public function newsletter(): BelongsTo
    {
        return $this->belongsTo(Newsletter::class, 'newsletter_id');
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class, 'user_id');
    }

    public function isPending(): bool
    {
        return $this->status === self::STATUS_PENDING;
    }

    /** In der Warteschlange, aber noch nicht zugestellt. */
    public function isQueued(): bool
    {
        return $this->status === self::STATUS_QUEUED;
    }

    public function isSent(): bool
    {
        return $this->status === self::STATUS_SENT;
    }

    public function isFailed(): bool
    {
        return $this->status === self::STATUS_FAILED;
    }
}
