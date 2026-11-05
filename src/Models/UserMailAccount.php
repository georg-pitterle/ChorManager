<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class UserMailAccount extends Model
{
    /**
     * Wie die Verbindung zum Postfach verschlüsselt wird. Die Werte spiegeln das
     * ENUM der Spalte `user_mail_accounts.imap_encryption`.
     *
     * `none` steht hier, weil die Spalte es kennt und bestehende Zeilen es
     * tragen. Wählen lässt es sich nicht mehr: Ohne Verschlüsselung ging das
     * Passwort im Klartext über das Netz. Die engere Liste dafür ist
     * `ProfileController::SELECTABLE_IMAP_ENCRYPTIONS` und heißt bewusst anders -
     * gleiche Trennung wie bei `Attendance::RECORDED_STATUSES` gegenüber
     * `SUPPORTED_STATUSES`.
     */
    public const IMAP_ENCRYPTION_SSL = 'ssl';
    public const IMAP_ENCRYPTION_TLS = 'tls';
    public const IMAP_ENCRYPTION_NONE = 'none';

    /** @var list<string> */
    public const IMAP_ENCRYPTIONS = [
        self::IMAP_ENCRYPTION_SSL,
        self::IMAP_ENCRYPTION_TLS,
        self::IMAP_ENCRYPTION_NONE,
    ];

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
