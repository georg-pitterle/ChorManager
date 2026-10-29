<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class SongResource extends Model
{
    /**
     * Die Arten, unter denen eine Liedquelle geführt wird. Derzeit nur der
     * Verweis auf eine fremde Seite.
     *
     * `song_resources.resource_type` ist ein `varchar(32)` und kein ENUM - es
     * gibt also keine Grenze, gegen die ein Wert stehen könnte. Gleiche Lage wie
     * bei `Attendance::SUPPORTED_STATUSES`, solange `attendance.status` noch ein
     * `varchar(50)` war: Ein Tippfehler landet als eigene Art in der Tabelle,
     * ohne dass etwas auffällt.
     *
     * Heißt `RESOURCE_TYPES` und nicht `TYPES`: Eine Liste mit diesem Namen
     * behauptet laut `ModelSchemaConsistencyTest::ENUM_MIRRORING_LISTS`, das
     * ENUM einer Spalte `type` zu spiegeln. Beides gibt es hier nicht, und der
     * Name soll nicht mehr versprechen als die Spalte halten kann.
     *
     * Vorher stand der Wert an sechs Stellen als `'link'` da - in
     * `Song::linkResources()`, dreimal im SongLibraryController, im
     * DownloadController und im Seed. Beide Richtungen schweigen bei einem
     * Vertipper: In einer Schreibstelle entsteht eine Quelle, die keine
     * Leseseite je findet; in einer Lesestelle kommt eine leere Liste zurück.
     */
    public const TYPE_LINK = 'link';

    /** @var list<string> */
    public const RESOURCE_TYPES = [
        self::TYPE_LINK,
    ];

    protected $table = 'song_resources';
    public $timestamps = true;

    protected $fillable = [
        'song_id',
        'resource_type',
        'title',
        'description',
        'url',
    ];

    public function song()
    {
        return $this->belongsTo(Song::class, 'song_id', 'id');
    }
}
