<?php

namespace ChorManager\Models;

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
        'attempts',
        'max_attempts',
        'next_attempt_at',
        'last_attempt_at',
        'sent_at',
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
        return $query->whereIn('status', ['queued', 'failed'])
            ->where(function ($q) {
                $q->whereNull('next_attempt_at')
                    ->orWhere('next_attempt_at', '<=', now());
            });
    }
    
    // Helpers
    public function isDelivered(): bool
    {
        return $this->status === 'sent';
    }
    
    public function isDeadLetter(): bool
    {
        return $this->status === 'dead';
    }
    
    public function canRetry(): bool
    {
        return $this->status === 'dead' && $this->attempts > 0;
    }
}
