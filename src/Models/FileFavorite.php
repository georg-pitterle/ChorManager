<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * Angeheftete Datei oder angehefteter Ordner eines Mitglieds. Genau eine der
 * beiden Spalten `file_id` / `folder_id` ist gesetzt.
 */
class FileFavorite extends Model
{
    public const UPDATED_AT = null;

    protected $table = 'file_favorites';

    protected $fillable = [
        'user_id',
        'file_id',
        'folder_id',
    ];

    protected $casts = [
        'user_id' => 'integer',
        'file_id' => 'integer',
        'folder_id' => 'integer',
    ];
}
