<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Eine selbst formulierte Frage einer Stimmgruppen-Liste, als Häkchen oder
 * Notiz. Die Art steht mit dem Anlegen fest.
 */
class VoiceGroupListColumn extends Model
{
    public const TYPE_CHECKBOX = 'checkbox';
    public const TYPE_NOTE = 'note';

    /** @var list<string> */
    public const TYPES = [self::TYPE_CHECKBOX, self::TYPE_NOTE];

    protected $table = 'voice_group_list_columns';

    protected $fillable = [
        'list_id',
        'label',
        'type',
        'sort_order',
    ];

    protected $casts = [
        'list_id' => 'integer',
        'sort_order' => 'integer',
    ];

    public function list(): BelongsTo
    {
        return $this->belongsTo(VoiceGroupList::class, 'list_id');
    }

    public function entries(): HasMany
    {
        return $this->hasMany(VoiceGroupListEntry::class, 'column_id');
    }

    public function isCheckbox(): bool
    {
        return $this->type === self::TYPE_CHECKBOX;
    }
}
