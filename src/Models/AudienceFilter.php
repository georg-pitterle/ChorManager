<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/** Zielgruppe aus Bedingungen; gehört genau einer Freigabe. */
class AudienceFilter extends Model
{
    public const UPDATED_AT = null;

    protected $table = 'audience_filters';

    protected $fillable = [];

    public function conditions(): HasMany
    {
        return $this->hasMany(AudienceFilterCondition::class, 'audience_filter_id');
    }
}
