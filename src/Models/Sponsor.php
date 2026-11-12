<?php

declare(strict_types=1);

namespace App\Models;

use App\Models\Concerns\HasEntityAttachments;
use App\Models\Concerns\HasLogoAttachments;
use Illuminate\Database\Eloquent\Model;

class Sponsor extends Model
{
    use HasEntityAttachments;
    use HasLogoAttachments;

    /** Der Wert, unter dem Anhänge an diesem Sponsor hängen - Mediadaten, allgemeine Unterlagen. */
    public const ENTITY_TYPE = 'sponsor';

    /**
     * Logo-Dateien hängen getrennt davon: beliebig viele je Sponsor, in
     * Grafikformaten bis 50 MB (App\Util\SponsorLogoUpload), und mit einem
     * eigenen Recht für die Zuständigen einer Zusage.
     */
    public const LOGO_ENTITY_TYPE = 'sponsor_logo';

    /**
     * Ob hinter dem Sponsor eine Organisation oder eine Einzelperson steht. Die
     * Werte spiegeln das ENUM der Spalte `sponsors.type`.
     *
     * Vorher standen sie nirgends benannt: `SponsorController` brachte
     * `['organization', 'person']` als eigene Aufzählung mit, drei Vorlagen
     * verglichen gegen Zeichenketten. Ein Vertipper wäre still geblieben, denn
     * das ENUM weist einen unbekannten Wert nicht in jedem Modus ab.
     */
    public const TYPE_ORGANIZATION = 'organization';
    public const TYPE_PERSON = 'person';

    /** @var list<string> */
    public const TYPES = [
        self::TYPE_ORGANIZATION,
        self::TYPE_PERSON,
    ];

    /**
     * Beschriftung je Typ, ausgeschrieben - für das Auswahlfeld und die
     * Kopfzeile der Einzelansicht.
     *
     * Steht hier und nicht in den Vorlagen, gleiche Begründung wie bei
     * `MailQueue::MAIL_TYPE_LABELS`: Die Zuordnung stand dreimal da, in
     * `sponsoring/sponsors/detail.twig` zweimal und in
     * `sponsoring/sponsors/index.twig` einmal.
     *
     * @var array<string, string>
     */
    public const TYPE_LABELS = [
        self::TYPE_ORGANIZATION => 'Organisation oder Firma',
        self::TYPE_PERSON => 'Einzelperson',
    ];

    /**
     * Dieselben Typen, knapp - für die Meta-Zeile der Übersicht, in der der Typ
     * mit Kategorie und Kontaktperson durch `·` getrennt in einer Zeile steht.
     *
     * Eine zweite Zuordnung und nicht die ausgeschriebene auch dort: Die
     * Übersicht schrieb schon vorher "Organisation" statt "Organisation oder
     * Firma", und das ist in dieser Zeile die richtige Länge. Die Doppelung
     * bleibt damit, aber benannt - vorher standen beide Fassungen unbenannt in
     * je einer Vorlage, und dass sie verschieden sind, war an keiner der beiden
     * Stellen zu sehen.
     *
     * @var array<string, string>
     */
    public const TYPE_SHORT_LABELS = [
        self::TYPE_ORGANIZATION => 'Organisation',
        self::TYPE_PERSON => 'Einzelperson',
    ];

    protected $table = 'sponsors';
    public $timestamps = true;

    protected $fillable = [
        'type',
        'category_id',
        'name',
        'contact_person',
        'email',
        'phone',
        'address',
        'website',
        'notes',
        'requests_blocked',
        'requests_blocked_note',
        'created_by_user_id',
    ];

    protected $casts = [
        'requests_blocked' => 'boolean',
    ];

    public function category()
    {
        return $this->belongsTo(SponsorCategory::class, 'category_id');
    }

    public function sponsorships()
    {
        return $this->hasMany(Sponsorship::class, 'sponsor_id');
    }

    public function contacts()
    {
        return $this->hasMany(SponsoringContact::class, 'sponsor_id');
    }
}
