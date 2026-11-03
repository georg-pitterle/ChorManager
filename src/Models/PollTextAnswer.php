<?php

declare(strict_types=1);

namespace App\Models;

use App\Models\Concerns\HasRandomUuid;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Die eigene Antwort auf eine offene Frage. Eine je Person und Frage.
 *
 * Wie PollVote ohne Zeitstempel und mit zufälliger UUID; bei anonymen Fragen
 * bleibt user_id leer.
 */
class PollTextAnswer extends Model
{
    use HasRandomUuid;

    public const MAX_LENGTH = 500;

    public $timestamps = false;

    protected $table = 'poll_text_answers';

    protected $fillable = ['poll_id', 'user_id', 'body'];

    protected $casts = [
        'poll_id' => 'integer',
        'user_id' => 'integer',
    ];

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class, 'user_id');
    }
}
