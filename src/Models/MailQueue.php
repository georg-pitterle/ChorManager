<?php

declare(strict_types=1);

namespace App\Models;

use Carbon\Carbon;
use Illuminate\Database\Eloquent\Model;

class MailQueue extends Model
{
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
