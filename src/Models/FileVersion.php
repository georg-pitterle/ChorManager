<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Eine gespeicherte Fassung einer Datei. Der Inhalt unter `storage_path` wird
 * nie überschrieben; eine neue Fassung bekommt immer einen neuen Pfad.
 */
class FileVersion extends Model
{
    public const UPDATED_AT = null;

    protected $table = 'file_versions';

    protected $fillable = [
        'file_id',
        'version_number',
        'storage_driver',
        'storage_path',
        'size',
        'mime_type',
        'sha256',
        'uploaded_by',
        'office_session_open',
        'office_saved_at',
    ];

    protected $casts = [
        'file_id' => 'integer',
        'version_number' => 'integer',
        'size' => 'integer',
        'uploaded_by' => 'integer',
        'office_session_open' => 'boolean',
        'office_saved_at' => 'datetime',
    ];

    public function file(): BelongsTo
    {
        return $this->belongsTo(StoredFile::class, 'file_id')->withTrashed();
    }

    public function uploader(): BelongsTo
    {
        return $this->belongsTo(User::class, 'uploaded_by');
    }
}
