<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class SongResource extends Model
{
    protected $table = 'song_resources';
    public $timestamps = true;

    protected $fillable = [
        'song_id',
        'resource_type',
        'title',
        'description',
        'url',
    ];

    public function song()
    {
        return $this->belongsTo(Song::class, 'song_id', 'id');
    }
}
