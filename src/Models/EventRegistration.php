<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class EventRegistration extends Model
{
    public const STATUS_YES = 'yes';
    public const STATUS_NO = 'no';
    public const STATUS_MAYBE = 'maybe';
    public const STATUSES = [self::STATUS_YES, self::STATUS_NO, self::STATUS_MAYBE];

    protected $table = 'event_registrations';

    protected $fillable = [
        'event_id',
        'user_id',
        'status',
        'note',
        'updated_by'
    ];

    protected $casts = [
        'event_id' => 'integer',
        'user_id' => 'integer',
        'updated_by' => 'integer',
    ];

    public function event()
    {
        return $this->belongsTo(Event::class, 'event_id', 'id');
    }

    public function user()
    {
        return $this->belongsTo(User::class, 'user_id', 'id');
    }

    public function updatedBy()
    {
        return $this->belongsTo(User::class, 'updated_by', 'id');
    }
}
