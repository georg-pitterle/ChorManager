<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class EventType extends Model
{
    public $timestamps = false;
    protected $table = 'event_types';
    protected $fillable = ['name', 'color', 'is_highlighted'];

    protected $casts = [
        'is_highlighted' => 'boolean',
    ];

    public function events(): HasMany
    {
        return $this->hasMany(Event::class);
    }
}
