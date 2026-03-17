<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class EventSeries extends Model
{
    public $timestamps = false;
    protected $table = 'event_series';

    protected $fillable = [
        'frequency',
        'recurrence_interval',
        'weekdays',
        'end_date'
    ];

    public function events(): HasMany
    {
        return $this->hasMany(Event::class, 'series_id', 'id');
    }
}
