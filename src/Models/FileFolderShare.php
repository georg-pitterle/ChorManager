<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Freigabe eines Ordners an eine Zielgruppe mit einer Stufe. Unterordner erben
 * die Freigaben ihrer Vorfahren; mehrere passende Freigaben ergeben die höchste
 * Stufe.
 */
class FileFolderShare extends Model
{
    public const UPDATED_AT = null;

    public const TYPE_ROLE = 'role';
    public const TYPE_USER = 'user';
    public const TYPE_VOICE_GROUP = 'voice_group';
    public const TYPE_PROJECT_MEMBERS = 'project_members';
    public const TYPE_ALL_MEMBERS = 'all_members';

    public const TYPES = [
        self::TYPE_ROLE,
        self::TYPE_USER,
        self::TYPE_VOICE_GROUP,
        self::TYPE_PROJECT_MEMBERS,
        self::TYPE_ALL_MEMBERS,
    ];

    public const LEVEL_NONE = 0;
    public const LEVEL_READ = 1;
    public const LEVEL_UPLOAD = 2;
    public const LEVEL_EDIT = 3;
    public const LEVEL_MANAGE = 4;

    public const LEVEL_LABELS = [
        self::LEVEL_READ => 'Lesen',
        self::LEVEL_UPLOAD => 'Hochladen',
        self::LEVEL_EDIT => 'Bearbeiten',
        self::LEVEL_MANAGE => 'Verwalten',
    ];

    protected $table = 'file_folder_shares';

    protected $fillable = [
        'folder_id',
        'target_type',
        'reference_id',
        'level',
        'created_by',
    ];

    protected $casts = [
        'folder_id' => 'integer',
        'reference_id' => 'integer',
        'level' => 'integer',
        'created_by' => 'integer',
    ];

    public function folder(): BelongsTo
    {
        return $this->belongsTo(FileFolder::class, 'folder_id');
    }
}
