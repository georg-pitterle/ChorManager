<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class SongAttachment extends Model
{
    protected $table = 'song_attachments';
    public $timestamps = false;

    protected $fillable = [
        'song_id',
        'filename',
        'original_name',
        'mime_type',
        'file_size',
        'file_content',
    ];

    public function song()
    {
        return $this->belongsTo(Song::class, 'song_id', 'id');
    }
}
