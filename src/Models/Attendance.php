<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Attendance extends Model
{
    public const STATUS_PRESENT = 'present';
    public const STATUS_EXCUSED = 'excused';
    public const STATUS_UNEXCUSED = 'unexcused';

    /**
     * Offen: das Mitglied ist noch nicht bewertet. Normalerweise existiert dann
     * gar kein Datensatz; er bleibt nur bestehen, wenn eine Notiz erfasst wurde,
     * die sonst verloren ginge.
     */
    public const STATUS_UNKNOWN = 'unknown';

    /** Status, die als tatsächliche Erfassung zählen. */
    public const RECORDED_STATUSES = [
        self::STATUS_PRESENT,
        self::STATUS_EXCUSED,
        self::STATUS_UNEXCUSED,
    ];

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
        return $this->belongsTo(Event::class, 'event_id', 'id');
    }

    public function user()
    {
        return $this->belongsTo(User::class, 'user_id', 'id');
    }
}
