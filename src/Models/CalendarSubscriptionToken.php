<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class CalendarSubscriptionToken extends Model
{
    protected $table = 'calendar_subscription_tokens';
    public $timestamps = false;

    protected $fillable = [
        'user_id',
        'token',
        'created_at',
    ];

    protected $casts = [
        'created_at' => 'datetime',
    ];

    public function user()
    {
        return $this->belongsTo(User::class, 'user_id', 'id');
    }
}
