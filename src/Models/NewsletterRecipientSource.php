<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * "Zielgruppe eines Termins" als Empfängerquelle eines Newsletters. Alle
 * übrigen Empfänger laufen über Zielgruppen-Filter (Newsletter::audienceFilters).
 */
class NewsletterRecipientSource extends Model
{
    public const TYPE_EVENT_ATTENDEES = 'event_attendees';

    /**
     * Die eine zulässige Quellenart, als Liste - sie spiegelt das ENUM der
     * Spalte `newsletter_recipient_sources.source_type`. Dass heute nur ein
     * Wert darin steht, macht die Liste nicht entbehrlich: Genau dann fällt eine
     * per Migration ergänzte Art ohne Wächter durch.
     *
     * @var list<string>
     */
    public const SOURCE_TYPES = [
        self::TYPE_EVENT_ATTENDEES,
    ];

    protected $table = 'newsletter_recipient_sources';
    public $timestamps = false;

    protected $fillable = [
        'newsletter_id',
        'source_type',
        'reference_id',
    ];
}
