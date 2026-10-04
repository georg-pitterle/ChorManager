<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/** Zielgruppe aus Bedingungen; gehört genau einem Besitzer. */
class AudienceFilter extends Model
{
    public const UPDATED_AT = null;

    /** Besitzer-Spalten; genau eine ist gesetzt (CHECK ab Migration 20261004090400). */
    public const OWNER_COLUMNS = [
        'event_id',
        'newsletter_id',
        'newsletter_template_id',
        'file_folder_share_id',
        'file_share_id',
    ];

    protected $table = 'audience_filters';

    protected $fillable = self::OWNER_COLUMNS;

    public function conditions(): HasMany
    {
        return $this->hasMany(AudienceFilterCondition::class, 'audience_filter_id');
    }

    /**
     * Bedingungen dieses Filters aus der geladenen Beziehung, Kategorien in
     * fester Reihenfolge, Kennungen aufsteigend.
     *
     * @return array<string, list<int>>
     */
    public function conditionSet(): array
    {
        $grouped = [];
        foreach ($this->conditions as $condition) {
            $grouped[(string) $condition->category][] = (int) $condition->reference_id;
        }

        $set = [];
        foreach (AudienceFilterCondition::CATEGORIES as $category) {
            if (isset($grouped[$category])) {
                $ids = array_values(array_unique($grouped[$category]));
                sort($ids);
                $set[$category] = $ids;
            }
        }

        return $set;
    }
}
