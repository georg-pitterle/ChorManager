<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class SheetArchiveLineItem extends Model
{
    protected $table = 'sheet_archive_line_items';
    public $timestamps = false;

    protected $fillable = [
        'sheet_archive_id',
        'voice_category',
        'count',
        'sort_order',
    ];

    protected $casts = [
        'count' => 'integer',
        'sort_order' => 'integer',
    ];

    public function sheetArchive(): BelongsTo
    {
        return $this->belongsTo(SheetArchive::class, 'sheet_archive_id', 'id');
    }
}
