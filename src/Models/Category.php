<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Category extends Model
{
    protected $table = 'repertoire_categories';
    public $timestamps = false;

    protected $fillable = [
        'name',
        'sort_order',
    ];

    public function songs()
    {
        return $this->belongsToMany(Song::class, 'song_category_assignments', 'repertoire_category_id', 'song_id');
    }
}
