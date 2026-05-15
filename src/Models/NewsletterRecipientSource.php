<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class NewsletterRecipientSource extends Model
{
    public const TYPE_PROJECT_MEMBERS = 'project_members';
    public const TYPE_EVENT_ATTENDEES = 'event_attendees';
    public const TYPE_ROLE = 'role';
    public const TYPE_USER = 'user';

    protected $table = 'newsletter_recipient_sources';
    public $timestamps = false;

    protected $fillable = [
        'newsletter_id',
        'source_type',
        'reference_id',
    ];
}
