<?php

declare(strict_types=1);

namespace App\Models;

use Carbon\Carbon;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Öffentlicher Link auf eine Datei. Nur der Hash des Tokens liegt in der
 * Datenbank; wer den Link verliert, legt einen neuen an.
 */
class FilePublicLink extends Model
{
    public const UPDATED_AT = null;

    protected $table = 'file_public_links';

    protected $fillable = [
        'file_id',
        'token_hash',
        'label',
        'password_hash',
        'expires_at',
        'revoked_at',
        'download_count',
        'last_used_at',
        'created_by',
    ];

    /**
     * Weder Hash noch Passwort-Hash gehören in eine serialisierte Ausgabe.
     *
     * @var list<string>
     */
    protected $hidden = [
        'token_hash',
        'password_hash',
    ];

    protected $casts = [
        'file_id' => 'integer',
        'download_count' => 'integer',
        'created_by' => 'integer',
        'expires_at' => 'datetime',
        'revoked_at' => 'datetime',
        'last_used_at' => 'datetime',
    ];

    public function file(): BelongsTo
    {
        return $this->belongsTo(StoredFile::class, 'file_id');
    }

    public function isUsable(): bool
    {
        if ($this->revoked_at !== null) {
            return false;
        }

        return $this->expires_at === null || $this->expires_at->greaterThan(Carbon::now());
    }

    public function hasPassword(): bool
    {
        return $this->password_hash !== null && $this->password_hash !== '';
    }
}
