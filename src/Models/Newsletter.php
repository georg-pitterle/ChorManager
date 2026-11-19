<?php

declare(strict_types=1);

namespace App\Models;

use App\Models\Concerns\HasAudienceConditionSets;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Newsletter extends Model
{
    use HasAudienceConditionSets;

    /**
     * Der Wert, unter dem Anhänge an diesem Newsletter hängen.
     *
     * Stand bis hierher nur als `NewsletterAttachmentService::ENTITY_TYPE` in
     * der Dienstschicht - der einzige `entity_type`, der nicht an seinem Modell
     * hing. Für die Morph-Map (`App\Util\EntityType`) braucht die Zuordnung ihn
     * hier, denn Modelle ziehen nichts aus `App\Services`; der Dienst verweist
     * jetzt hierauf.
     *
     * Der Newsletter benutzt `Concerns\HasEntityAttachments` nicht: Seine
     * Anhänge tragen zusätzlich `delivery_mode`, und NewsletterAttachmentService
     * führt sie mit eigenen Abfragen.
     */
    public const ENTITY_TYPE = 'newsletter';

    public const STATUS_DRAFT = 'draft';
    public const STATUS_SENT = 'sent';
    public const SUPPORTED_STATUSES = [
        self::STATUS_DRAFT,
        self::STATUS_SENT,
    ];

    /**
     * Spalten, die die Übersicht laden darf.
     *
     * `content_html` ist longtext und trägt den ganzen Mailtext. Die Übersicht
     * zeigt ihn nicht an - sie listet Titel, Zustand, Empfängerzahl und Datum -,
     * las ihn aber für jede Zeile mit, und `NewsletterController::index()` gibt
     * alle Newsletter eines Zustands ohne Blätterwerk auf einmal aus. Bei einem
     * Verein mit ein paar Jahren Rundbriefen liegt damit jeder je verschickte
     * Mailtext gleichzeitig im Speicher, nur um verworfen zu werden.
     *
     * Dieselbe Grenze wie `MailQueue::LIST_COLUMNS`, dort aus demselben Grund
     * für `body_html`, und derselbe Fehler, der das Repertoire ins
     * Speicherlimit gefahren hat (`Concerns\HasEntityAttachments`): Ein Fatal
     * Error im Speicherlimit geht an Monolog vorbei - leere 500-Seite, nichts
     * im Log.
     *
     * `project_id` und `created_by` tragen die vorab geladenen Beziehungen,
     * `sent_at` die Sortierung der verschickten Newsletter. Wer eine weitere
     * Spalte in der Übersicht braucht, nimmt sie hier auf - nicht in einen
     * eigenen `select()` an der Aufrufstelle, sonst fällt die Grenze wieder
     * auseinander.
     *
     * @var list<string>
     */
    public const LIST_COLUMNS = [
        'id',
        'project_id',
        'title',
        'status',
        'recipient_count',
        'created_by',
        'created_at',
        'sent_at',
    ];

    protected $table = 'newsletters';
    public $timestamps = false;

    protected $fillable = [
        'project_id',
        'title',
        'content_html',
        'status',
        'recipient_count',
        'locked_by',
        'locked_at',
        'created_by',
        'sent_at',
    ];

    /**
     * Zweite Absicherung neben LIST_COLUMNS: Wird ein Newsletter doch einmal
     * mit allen Spalten geladen und danach serialisiert, bleibt der Mailtext
     * aus der Ausgabe. Gleiche Begründung wie bei `MailQueue::$body_html` - ein
     * longtext in einer Logzeile sprengt jede Zeile im Container-Log.
     *
     * Auf `$newsletter->content_html` wirkt sich das nicht aus: Vorschau,
     * Versand und das Übernehmen in eine Vorlage lesen die Eigenschaft direkt.
     *
     * @var list<string>
     */
    protected $hidden = [
        'content_html',
    ];

    protected $casts = [
        'project_id' => 'integer',
        'recipient_count' => 'integer',
        'locked_by' => 'integer',
        'created_by' => 'integer',
        'created_at' => 'datetime',
        'updated_at' => 'datetime',
        'locked_at' => 'datetime',
        'sent_at' => 'datetime',
    ];

    public function project(): BelongsTo
    {
        return $this->belongsTo(Project::class, 'project_id');
    }

    public function createdBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function lockedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'locked_by');
    }

    public function recipients(): HasMany
    {
        return $this->hasMany(NewsletterRecipient::class, 'newsletter_id');
    }

    /**
     * Zielgruppen-Zeilen; dazu kommen die Termine aus recipientSources().
     *
     * Aufsteigend nach `id` - gleiche Begründung wie bei Event::audienceFilters():
     * Ohne die Sortierung steht die vorab geladene Zielgruppe anders herum als
     * die in HasAudienceConditionSets nachgeladene.
     */
    public function audienceFilters(): HasMany
    {
        return $this->hasMany(AudienceFilter::class, 'newsletter_id', 'id')
            ->orderBy('audience_filters.id');
    }

    /** Nur noch "Zielgruppe eines Termins" (event_attendees). */
    public function recipientSources(): HasMany
    {
        return $this->hasMany(NewsletterRecipientSource::class, 'newsletter_id');
    }

    public function archive(): HasMany
    {
        return $this->hasMany(NewsletterArchive::class, 'newsletter_id');
    }

    public function isDraft(): bool
    {
        return $this->status === self::STATUS_DRAFT;
    }

    /**
     * Stehen die beiden Sperrspalten? Mehr beantwortet diese Frage nicht.
     *
     * Insbesondere kennt sie die Ablauffrist aus NewsletterLockingService nicht:
     * Ein Vermerk, den jemand vor Stunden liegen gelassen hat, steht hier immer
     * noch auf `true`, obwohl acquireLock() ihn längst überschreiben würde. Wer
     * entscheiden muss, ob gerade wirklich jemand an dem Entwurf sitzt - Versand,
     * Testmail, Löschen, Sperr-Abfrage -, fragt
     * `NewsletterLockingService::isLockedByOther()` bzw. `hasActiveLock()`.
     *
     * Genau diese Verwechslung hielt einen liegengebliebenen Entwurf dauerhaft
     * fest: bearbeiten durfte ihn jeder, löschen niemand mehr.
     */
    public function isLocked(): bool
    {
        return $this->locked_by !== null && $this->locked_at !== null;
    }

    public function isSent(): bool
    {
        return $this->status === self::STATUS_SENT;
    }
}
