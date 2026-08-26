<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Empfängerquelle einer Newsletter-Vorlage. Spiegelt bewusst die Typen von
 * NewsletterRecipientSource, damit eine Vorlage die Auswahl eines Newsletters
 * unverändert aufnehmen und wieder ausgeben kann.
 */
class NewsletterTemplateRecipientSource extends Model
{
    public const TYPE_PROJECT_MEMBERS = NewsletterRecipientSource::TYPE_PROJECT_MEMBERS;
    public const TYPE_EVENT_ATTENDEES = NewsletterRecipientSource::TYPE_EVENT_ATTENDEES;
    public const TYPE_ROLE = NewsletterRecipientSource::TYPE_ROLE;
    public const TYPE_USER = NewsletterRecipientSource::TYPE_USER;

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
