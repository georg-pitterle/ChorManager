<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Newsletter extends Model
{
    protected $table = 'newsletters';
    public $timestamps = false;

    protected $fillable = [
        'project_id',
        'event_id',
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
        'event_id' => 'integer',
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

    public function event(): BelongsTo
    {
        return $this->belongsTo(Event::class, 'event_id');
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

    public function archive(): HasMany
    {
        return $this->hasMany(NewsletterArchive::class, 'newsletter_id');
    }

    public function isDraft(): bool
    {
        return $this->status === 'draft';
    }

    public function isLocked(): bool
    {
        return $this->locked_by !== null && $this->locked_at !== null;
    }

    public function isSent(): bool
    {
        return $this->status === 'sent';
    }
}
