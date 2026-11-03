<?php

declare(strict_types=1);

namespace App\Models;

use App\Models\Concerns\HasRandomUuid;
use Illuminate\Database\Eloquent\Model;

/**
 * Eine Stimme für eine Antwort.
 *
 * Die Id ist eine zufällige UUID v4 (HasRandomUuid), und es gibt keine
 * Zeitstempel: Beides bewahrte sonst die Reihenfolge der Stimmen. Bei anonymen
 * Umfragen bleibt user_id leer.
 */
class PollVote extends Model
{
    use HasRandomUuid;

    public $timestamps = false;

    protected $table = 'poll_votes';

    protected $fillable = ['poll_id', 'option_id', 'user_id'];

    protected $casts = [
        'poll_id' => 'integer',
        'option_id' => 'integer',
        'user_id' => 'integer',
    ];
}
