<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** Eine feste Antwort einer Umfrage. */
class PollOption extends Model
{
    public $timestamps = false;

    protected $table = 'poll_options';

    protected $fillable = ['poll_id', 'label', 'sort_order'];

    protected $casts = [
        'poll_id' => 'integer',
        'sort_order' => 'integer',
    ];

    public function poll(): BelongsTo
    {
        return $this->belongsTo(Poll::class, 'poll_id');
    }
}
