<?php

declare(strict_types=1);

namespace App\Models;

use App\Util\EntityType;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\MorphTo;

class Attachment extends Model
{
    /**
     * Wie ein Anhang beim Empfänger ankommt: an der Mail oder als Link auf die
     * Leseseite. Die Werte spiegeln das ENUM der Spalte
     * `attachments.delivery_mode` (Migration 20260719181000).
     *
     * Sie standen bis hierher nur als `NewsletterAttachmentService::MODE_ATTACH`
     * und `MODE_LINK` in der Dienstschicht und als Zeichenketten in
     * `newsletters/edit.twig`. Gegen das ENUM stand damit nichts - eine per
     * Migration ergänzte Zustellart hätte keine Prüfmethode erkannt, und
     * `NewsletterAttachmentService::updateMode()` hätte sie abgewiesen, obwohl
     * die Spalte sie kennt. Der Dienst verweist jetzt hierauf.
     */
    public const DELIVERY_MODE_ATTACH = 'attach';
    public const DELIVERY_MODE_LINK = 'link';

    /** @var list<string> */
    public const DELIVERY_MODES = [
        self::DELIVERY_MODE_ATTACH,
        self::DELIVERY_MODE_LINK,
    ];

    protected $table = 'attachments';
    public $timestamps = false;

    /**
     * Alles außer `file_content`. Festgelegt hier am Modell, weil die Beziehung
     * `HasEntityAttachments::attachments()` sie braucht und Modelle nichts aus
     * `App\Services` ziehen; `EntityAttachmentService::METADATA_COLUMNS` verweist
     * darauf.
     *
     * @var list<string>
     */
    public const METADATA_COLUMNS = [
        'id',
        'entity_type',
        'entity_id',
        'filename',
        'original_name',
        'mime_type',
        'file_size',
        'created_at',
    ];

    // Use string type for created_at if we aren't using Laravel's automatic timestamping, or just let Eloquent handle it
    protected $fillable = [
        'entity_type',
        'entity_id',
        'filename',
        'original_name',
        'mime_type',
        'file_size',
        'delivery_mode',
        'file_content',
        'created_at'
    ];

    /**
     * Zweite Absicherung neben den Spaltenlisten (`METADATA_COLUMNS` in der
     * Beziehung `HasEntityAttachments::attachments()` und die eigenen `select()`
     * der Aufrufer): Wird ein
     * Anhang doch einmal mit allen Spalten geladen und danach serialisiert, bleibt
     * der Dateiinhalt aus der Ausgabe.
     *
     * Zwei Gründe. Anhänge sind rechtegeprüfte Inhalte - was über die
     * Serialisierung in eine JSON-Antwort gerät, umgeht die Prüfung im
     * AttachmentController. Und ein BLOB in einer Logzeile sprengt jede Zeile im
     * Container-Log.
     *
     * Auf `$attachment->file_content` wirkt sich das nicht aus - die Auslieferung
     * in AttachmentResponseFactory liest die Eigenschaft direkt.
     *
     * @var list<string>
     */
    protected $hidden = [
        'file_content',
    ];

    protected $casts = [
        'created_at' => 'datetime',
    ];

    /**
     * Meldet die Morph-Map an, bevor die erste Zeile gelesen wird.
     *
     * `booted()` läuft einmal je Prozess, beim ersten Zugriff auf das Modell -
     * also immer vor dem Auflösen eines `entity_type`. Warum hier und nicht in
     * `src/Dependencies.php`, steht in `EntityType::register()`.
     */
    protected static function booted(): void
    {
        EntityType::register();
    }

    /**
     * Rückrichtung des polymorphen Anhangs auf seine Entität - Lied, Projekt,
     * Aufgabe, Sponsor, Vereinbarung, Buchung, Newsletter.
     *
     * Bis hierher gab es nur `song()`: eine belongsTo auf Lieder, die sich mit
     * `whereRaw('1 = 0')` selbst abschaltete, wenn die Zeile etwas anderes
     * meinte. Das trug nur den Einzelzugriff. Eager Loading
     * (`Attachment::with('song')`) blieb für jede Zeile leer, weil Eloquent die
     * Eager-Bedingung aus einer leeren Modellinstanz baut, deren `entity_type`
     * null ist - und für alles außer Liedern gab es die Rückrichtung überhaupt
     * nicht.
     *
     * `morphTo` kennt das Muster: Es gruppiert die geladenen Zeilen nach
     * `entity_type`, löst jeden Namen über die Map in `App\Util\EntityType` auf
     * und holt je Tabelle eine Abfrage. Eine gemischte Liste kostet damit so
     * viele Abfragen, wie Entitätsarten darin vorkommen, statt einer je Zeile.
     *
     * Eine Zeile ohne passende Entität liefert `null`: Anhänge stehen ohne
     * Fremdschlüssel da, eine verwaiste Zeile ist also möglich.
     */
    public function entity(): MorphTo
    {
        return $this->morphTo(__FUNCTION__, 'entity_type', 'entity_id');
    }
}
