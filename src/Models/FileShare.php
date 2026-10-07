<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasOne;

/**
 * Freigabe einer einzelnen Datei. Zieltypen wie bei Ordnern, Stufen nur Lesen
 * und Bearbeiten: Umbenennen, Verschieben und Löschen bleiben Sache des
 * Ordners, eine Dateifreigabe erlaubt höchstens eine neue Version.
 */
class FileShare extends Model
{
    public const UPDATED_AT = null;

    /**
     * Die beiden zulässigen Stufen mit ihrem Label. Die Labels kommen aus
     * FileFolderShare::LEVEL_LABELS und stehen nicht ein zweites Mal hier -
     * sonst hieße dieselbe Stufe nach einer Umbenennung an Datei und Ordner
     * unterschiedlich.
     *
     * @var array<int, string>
     */
    public const LEVELS = [
        FileFolderShare::LEVEL_READ => FileFolderShare::LEVEL_LABELS[FileFolderShare::LEVEL_READ],
        FileFolderShare::LEVEL_EDIT => FileFolderShare::LEVEL_LABELS[FileFolderShare::LEVEL_EDIT],
    ];

    protected $table = 'file_shares';

    protected $fillable = [
        'file_id',
        'level',
        'created_by',
    ];

    protected $casts = [
        'file_id' => 'integer',
        'level' => 'integer',
        'created_by' => 'integer',
    ];

    public function filter(): HasOne
    {
        return $this->hasOne(AudienceFilter::class, 'file_share_id');
    }

    public function file(): BelongsTo
    {
        return $this->belongsTo(StoredFile::class, 'file_id');
    }
}
