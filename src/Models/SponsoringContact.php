<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class SponsoringContact extends Model
{
    /**
     * Auf welchem Weg der Kontakt zustande kam. Die Werte spiegeln das ENUM der
     * Spalte `sponsoring_contacts.type`.
     *
     * Vorher standen sie als `SponsoringContactController::ALLOWED_CONTACT_TYPES`
     * in der HTTP-Schicht, also als Aufzählung neben dem ENUM statt als dessen
     * Spiegel. Der Controller verweist jetzt hierauf.
     */
    public const TYPE_CALL = 'call';
    public const TYPE_EMAIL = 'email';
    public const TYPE_MEETING = 'meeting';
    public const TYPE_LETTER = 'letter';
    public const TYPE_OTHER = 'other';

    /** @var list<string> */
    public const TYPES = [
        self::TYPE_CALL,
        self::TYPE_EMAIL,
        self::TYPE_MEETING,
        self::TYPE_LETTER,
        self::TYPE_OTHER,
    ];

    protected $table = 'sponsoring_contacts';
    public $timestamps = false;

    protected $fillable = [
        'sponsor_id',
        'sponsorship_id',
        'user_id',
        'contact_date',
        'type',
        'summary',
        'follow_up_date',
        'follow_up_done',
    ];

    /**
     * `$timestamps` steht auf false, deshalb kümmert sich Eloquent nicht von
     * selbst um `created_at` - ohne Cast käme die Spalte als Zeichenkette.
     *
     * @var array<string, string>
     */
    protected $casts = [
        'contact_date'   => 'date',
        'follow_up_date' => 'date',
        'follow_up_done' => 'boolean',
        'created_at'     => 'datetime',
    ];

    public function sponsor()
    {
        return $this->belongsTo(Sponsor::class, 'sponsor_id');
    }

    public function sponsorship()
    {
        return $this->belongsTo(Sponsorship::class, 'sponsorship_id');
    }

    public function user()
    {
        return $this->belongsTo(User::class, 'user_id');
    }
}
