<?php
declare(strict_types = 1)
;

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Attendance extends Model
{
    protected $table = 'attendance';
    public $timestamps = false;

    protected $fillable = [
        'event_id',
        'user_id',
        'status',
        'note'
    ];

    public function event()
    {
        return $this->belongsTo(Event::class , 'event_id', 'id');
    }

    public function user()
    {
        return $this->belongsTo(User::class , 'user_id', 'id');
    }
}
