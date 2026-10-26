<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Arbeitsliste einer Stimmgruppe, geführt von deren Stimmvertretungen.
 *
 * Sie gehört der Stimmgruppe, nicht der Person, die sie angelegt hat. Welche
 * Mitglieder darin stehen, berechnet VoiceGroupListRows bei jedem Aufruf.
 */
class VoiceGroupList extends Model
{
    protected $table = 'voice_group_lists';

    protected $fillable = [
        'voice_group_id',
        'title',
        'project_id',
        'archived_at',
        'created_by_user_id',
    ];

    protected $casts = [
        'voice_group_id' => 'integer',
        'project_id' => 'integer',
        'created_by_user_id' => 'integer',
        'archived_at' => 'datetime',
    ];

    public function columns(): HasMany
    {
        return $this->hasMany(VoiceGroupListColumn::class, 'list_id')->orderBy('sort_order')->orderBy('id');
    }

    public function voiceGroup(): BelongsTo
    {
        return $this->belongsTo(VoiceGroup::class, 'voice_group_id');
    }

    public function project(): BelongsTo
    {
        return $this->belongsTo(Project::class, 'project_id');
    }

    public function isArchived(): bool
    {
        return $this->archived_at !== null;
    }
}
