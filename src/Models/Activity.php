<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Activity extends Model
{
    protected $table = 'activities';
    public $timestamps = false;

    protected $fillable = [
        'entity_type',
        'entity_id',
        'user_id',
        'action',
        'description',
        'created_at'
    ];

    public function user()
    {
        return $this->belongsTo(User::class, 'user_id');
    }
}
