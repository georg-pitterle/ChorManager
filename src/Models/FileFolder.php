<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * Ordner der Dateiverwaltung. Ohne `parent_id` ist es ein Teamordner: nur dort
 * gilt ein Kontingent, und nur dort legt jemand mit "Dateiverwaltung verwalten"
 * neue Wurzeln an.
 *
 * Der Papierkorb ist `deleted_at` (SoftDeletes). Landet ein Ordner darin, bleiben
 * seine Unterordner und Dateien unverändert - sie sind über den Vorfahren
 * unerreichbar und kommen mit ihm zurück.
 */
class FileFolder extends Model
{
    use SoftDeletes;

    protected $table = 'file_folders';

    protected $fillable = [
        'parent_id',
        'name',
        'quota_bytes',
        'created_by',
        'deleted_by',
    ];

    protected $casts = [
        'parent_id' => 'integer',
        'quota_bytes' => 'integer',
        'created_by' => 'integer',
        'deleted_by' => 'integer',
    ];

    public function isRoot(): bool
    {
        return $this->parent_id === null;
    }

    public function parent(): BelongsTo
    {
        return $this->belongsTo(self::class, 'parent_id');
    }

    public function children(): HasMany
    {
        return $this->hasMany(self::class, 'parent_id');
    }

    public function files(): HasMany
    {
        return $this->hasMany(StoredFile::class, 'folder_id');
    }

    public function shares(): HasMany
    {
        return $this->hasMany(FileFolderShare::class, 'folder_id');
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }
}
