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

    /**
     * Beschriftung je Antwort für die Oberfläche.
     *
     * Steht hier und nicht in den Vorlagen - gleiche Begründung wie bei
     * `MailQueue::MAIL_TYPE_LABELS` und `Task::STATUS_LABELS`: Die Zuordnung
     * stand wortgleich viermal da, als `answer_labels` in
     * `registrations/index.twig`, `registrations/detail.twig` und
     * `dashboard/index.twig` und ein viertes Mal als `STATUS_LABELS` in
     * `public/js/registrations.js`, das die Zeile nach einer gespeicherten
     * Antwort neu schreibt. Das JavaScript liest den Text jetzt am Knopf ab
     * (`data-answer-label`) und kennt die Antworten nicht mehr.
     *
     * @var array<string, string>
     */
    public const STATUS_LABELS = [
        self::STATUS_YES => 'Zusage',
        self::STATUS_MAYBE => 'Vielleicht',
        self::STATUS_NO => 'Absage',
    ];

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
