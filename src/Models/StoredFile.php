<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * Datei der Dateiverwaltung. Nicht `File`, weil der Name im Code ständig mit
 * Dateisystem-Begriffen zusammenstößt.
 *
 * `size` und `mime_type` spiegeln die aktuelle Version, damit Listen ohne
 * Verknüpfung auf `file_versions` auskommen.
 */
class StoredFile extends Model
{
    use SoftDeletes;

    protected $table = 'files';

    protected $fillable = [
        'folder_id',
        'name',
        'current_version_id',
        'size',
        'mime_type',
        'created_by',
        'updated_by',
        'deleted_by',
    ];

    protected $casts = [
        'folder_id' => 'integer',
        'current_version_id' => 'integer',
        'size' => 'integer',
        'created_by' => 'integer',
        'updated_by' => 'integer',
        'deleted_by' => 'integer',
    ];

    public function folder(): BelongsTo
    {
        return $this->belongsTo(FileFolder::class, 'folder_id')->withTrashed();
    }

    public function versions(): HasMany
    {
        return $this->hasMany(FileVersion::class, 'file_id')->orderByDesc('version_number');
    }

    public function currentVersion(): BelongsTo
    {
        return $this->belongsTo(FileVersion::class, 'current_version_id');
    }

    public function updater(): BelongsTo
    {
        return $this->belongsTo(User::class, 'updated_by');
    }
}
