<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * Ein Eintrag in der Glocke einer Person.
 */
class UserNotification extends Model
{
    protected $table = 'user_notifications';

    public $timestamps = false;

    protected $fillable = [
        'user_id',
        'notification_type',
        'actor_user_id',
        'title',
        'body',
        'link',
        'entity_type',
        'entity_id',
        'comment_id',
        'read_at',
        'created_at',
    ];

    /**
     * @var array<string, string>
     */
    protected $casts = [
        'user_id' => 'integer',
        'actor_user_id' => 'integer',
        'entity_id' => 'integer',
        'comment_id' => 'integer',
        'read_at' => 'datetime',
        'created_at' => 'datetime',
    ];

    public function user()
    {
        return $this->belongsTo(User::class, 'user_id');
    }

    public function actor()
    {
        return $this->belongsTo(User::class, 'actor_user_id');
    }
}
