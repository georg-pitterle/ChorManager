<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class SheetArchive extends Model
{
    protected $table = 'sheet_archives';
    public $timestamps = false;

    protected $fillable = [
        'song_id',
        'archive_number',
        'location',
    ];

    private ?int $totalCountCache = null;

    public function song(): BelongsTo
    {
        return $this->belongsTo(Song::class, 'song_id', 'id');
    }

    public function lineItems(): HasMany
    {
        return $this->hasMany(SheetArchiveLineItem::class, 'sheet_archive_id', 'id');
    }

    /**
     * Calculate the total quantity count from all line items.
     * 
     * Caches result during request lifecycle to prevent N+1 queries.
     * Use formatArchiveResponse() to calculate once for API responses.
     */
    public function getTotalCount(): int
    {
        if ($this->totalCountCache !== null) {
            return $this->totalCountCache;
        }

        $this->totalCountCache = (int) $this->lineItems()->sum('count');
        return $this->totalCountCache;
    }
}
