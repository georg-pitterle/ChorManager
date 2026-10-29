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
     * Die Liste spiegelt das ENUM aus Migration 20261029120000. Bis dahin war
     * `song_resources.resource_type` ein `varchar(32)`: Es gab keine Grenze,
     * gegen die ein Wert hätte stehen können, und ein Tippfehler landete als
     * eigene Art in der Tabelle, ohne dass etwas auffiel. Gleicher Weg wie bei
     * `attendance.status`, das aus demselben Grund ein ENUM geworden ist.
     * Zusammengehalten werden Liste und Spalte von ModelSchemaConsistencyTest.
     *
     * Heißt `RESOURCE_TYPES` und nicht `TYPES`: Eine Liste mit diesem Namen
     * behauptet laut `ModelSchemaConsistencyTest::ENUM_MIRRORING_LISTS`, das
     * ENUM einer Spalte `type` zu spiegeln - die Spalte heißt hier anders.
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
