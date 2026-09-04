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

    /**
     * Das IMAP-Passwort liegt zwar verschlüsselt in der Spalte, ist aber der
     * Zugang zum fremden Postfach: Wer den Wert zusammen mit dem Schlüssel aus
     * der Umgebung hat, liest die Mails mit. In JSON-Antworten, Logzeilen und
     * Fehlerausgaben, die ein Modell mitschreiben, hat er deshalb nichts
     * verloren - gleiche Begründung wie bei User::$hidden.
     *
     * Auf `$account->imap_password_enc` wirkt sich das nicht aus: MailBadgeService
     * und RotateMailCredentialKeyCommand lesen die Eigenschaft direkt.
     *
     * @var list<string>
     */
    protected $hidden = [
        'imap_password_enc',
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
