<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Comment extends Model
{
    protected $table = 'comments';
    public $timestamps = false; // We define created_at and updated_at explicitly or manage them

    protected $fillable = [
        'entity_type',
        'entity_id',
        'user_id',
        'comment',
        'is_private',
        'created_at',
        'updated_at'
    ];

    protected $casts = [
        'is_private' => 'boolean',
        'created_at' => 'datetime',
        'updated_at' => 'datetime',
    ];

    public function user()
    {
        return $this->belongsTo(User::class, 'user_id');
    }
}
