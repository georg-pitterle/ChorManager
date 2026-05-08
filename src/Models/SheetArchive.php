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
     * Executes a database query to sum the count values.
     * Consider caching if called frequently within request lifecycle.
     */
    public function getTotalCount(): int
    {
        return (int) $this->lineItems()->sum('count');
    }
}
