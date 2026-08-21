<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
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

    /**
     * Sichtbare Notizen: alle oeffentlichen und die eigenen privaten. Eine
     * private Notiz gehoert nur der Person, die sie geschrieben hat - ohne
     * angemeldete Person ist deshalb nur der oeffentliche Teil sichtbar.
     *
     * @param Builder<Comment> $query
     * @return Builder<Comment>
     */
    public function scopeVisibleTo(Builder $query, ?int $userId): Builder
    {
        return $query->where(function (Builder $grouped) use ($userId): void {
            $grouped->where('is_private', false);

            if ($userId !== null && $userId > 0) {
                $grouped->orWhere(function (Builder $own) use ($userId): void {
                    $own->where('is_private', true)->where('user_id', $userId);
                });
            }
        });
    }
}
