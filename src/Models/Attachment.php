<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Attachment extends Model
{
    protected $table = 'attachments';
    public $timestamps = false;

    // Use string type for created_at if we aren't using Laravel's automatic timestamping, or just let Eloquent handle it
    protected $fillable = [
        'entity_type',
        'entity_id',
        'filename',
        'original_name',
        'mime_type',
        'file_size',
        'file_content',
        'created_at'
    ];

    public function song()
    {
        return $this->belongsTo(Song::class, 'entity_id', 'id')
            ->where($this->getTable() . '.entity_type', 'song');
    }
}
