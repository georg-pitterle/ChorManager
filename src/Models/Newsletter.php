<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Newsletter extends Model
{
    public const STATUS_DRAFT = 'draft';
    public const STATUS_SENT = 'sent';
    public const SUPPORTED_STATUSES = [
        self::STATUS_DRAFT,
        self::STATUS_SENT,
    ];

    protected $table = 'newsletters';
    public $timestamps = false;

    protected $fillable = [
        'project_id',
        'title',
        'content_html',
        'status',
        'recipient_count',
        'locked_by',
        'locked_at',
        'created_by',
        'sent_at',
    ];

    protected $casts = [
        'project_id' => 'integer',
        'recipient_count' => 'integer',
        'locked_by' => 'integer',
        'created_by' => 'integer',
        'created_at' => 'datetime',
        'updated_at' => 'datetime',
        'locked_at' => 'datetime',
        'sent_at' => 'datetime',
    ];

    public function project(): BelongsTo
    {
        return $this->belongsTo(Project::class, 'project_id');
    }

    public function createdBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function lockedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'locked_by');
    }

    public function recipients(): HasMany
    {
        return $this->hasMany(NewsletterRecipient::class, 'newsletter_id');
    }

    public function recipientSources(): HasMany
    {
        return $this->hasMany(NewsletterRecipientSource::class, 'newsletter_id');
    }

    public function archive(): HasMany
    {
        return $this->hasMany(NewsletterArchive::class, 'newsletter_id');
    }

    public function isDraft(): bool
    {
        return $this->status === self::STATUS_DRAFT;
    }

    /**
     * Stehen die beiden Sperrspalten? Mehr beantwortet diese Frage nicht.
     *
     * Insbesondere kennt sie die Ablauffrist aus NewsletterLockingService nicht:
     * Ein Vermerk, den jemand vor Stunden liegen gelassen hat, steht hier immer
     * noch auf `true`, obwohl acquireLock() ihn längst überschreiben würde. Wer
     * entscheiden muss, ob gerade wirklich jemand an dem Entwurf sitzt - Versand,
     * Testmail, Löschen, Sperr-Abfrage -, fragt
     * `NewsletterLockingService::isLockedByOther()` bzw. `hasActiveLock()`.
     *
     * Genau diese Verwechslung hielt einen liegengebliebenen Entwurf dauerhaft
     * fest: bearbeiten durfte ihn jeder, löschen niemand mehr.
     */
    public function isLocked(): bool
    {
        return $this->locked_by !== null && $this->locked_at !== null;
    }

    public function isSent(): bool
    {
        return $this->status === self::STATUS_SENT;
    }
}
