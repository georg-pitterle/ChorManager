<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * "Zielgruppe eines Termins" als Empfängerquelle einer Newsletter-Vorlage.
 * Spiegelt NewsletterRecipientSource; alle übrigen Empfänger laufen über
 * Zielgruppen-Filter (NewsletterTemplate::audienceFilters).
 */
class NewsletterTemplateRecipientSource extends Model
{
    public const TYPE_EVENT_ATTENDEES = NewsletterRecipientSource::TYPE_EVENT_ATTENDEES;

    protected $table = 'newsletter_template_recipient_sources';
    public $timestamps = false;

    protected $fillable = [
        'template_id',
        'source_type',
        'reference_id',
    ];

    protected $casts = [
        'template_id' => 'integer',
        'reference_id' => 'integer',
    ];

    public function template(): BelongsTo
    {
        return $this->belongsTo(NewsletterTemplate::class, 'template_id');
    }
}
