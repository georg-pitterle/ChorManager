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

    protected $table = 'newsletter_recipient_sources';
    public $timestamps = false;

    protected $fillable = [
        'newsletter_id',
        'source_type',
        'reference_id',
    ];
}
