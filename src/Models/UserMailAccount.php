<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class UserMailAccount extends Model
{
    public $timestamps = true;

    protected $table = 'user_mail_accounts';

    protected $fillable = [
        'user_id',
        'imap_host',
        'imap_port',
        'imap_encryption',
        'smtp_host',
        'smtp_port',
        'smtp_encryption',
        'imap_username',
        'imap_password_enc',
        'imap_enabled',
        'mail_badge_enabled',
        'external_webmail_url',
        'mail_last_unseen_count',
        'mail_last_uid_seen',
        'mail_last_checked_at',
    ];

    protected $casts = [
        'imap_enabled' => 'boolean',
        'mail_badge_enabled' => 'boolean',
        'mail_last_unseen_count' => 'integer',
        'mail_last_checked_at' => 'datetime',
        'created_at' => 'datetime',
        'updated_at' => 'datetime',
    ];

    public function user()
    {
        return $this->belongsTo(User::class, 'user_id', 'id');
    }
}
