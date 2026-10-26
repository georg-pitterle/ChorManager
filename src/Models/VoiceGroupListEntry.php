<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Häkchen oder Notiz eines Mitglieds in einer Spalte. Existiert nur, solange
 * etwas gesetzt ist: Zurücknehmen und Leeren löschen die Zeile.
 */
class VoiceGroupListEntry extends Model
{
    protected $table = 'voice_group_list_entries';

    protected $fillable = [
        'column_id',
        'user_id',
        'checked_at',
        'note',
        'updated_by_user_id',
    ];

    protected $casts = [
        'column_id' => 'integer',
        'user_id' => 'integer',
        'updated_by_user_id' => 'integer',
        'checked_at' => 'datetime',
    ];

    public function updatedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'updated_by_user_id');
    }
}
