<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class SheetArchive extends Model
{
    protected $table = 'sheet_archives';
    public $timestamps = false;

    protected $fillable = [
        'song_id',
        'archive_number',
        'location',
    ];

    public function song(): BelongsTo
    {
        return $this->belongsTo(Song::class, 'song_id', 'id');
    }

    public function lineItems(): HasMany
    {
        return $this->hasMany(SheetArchiveLineItem::class, 'sheet_archive_id', 'id');
    }

    /**
     * Gesamtzahl der Exemplare über alle Positionen.
     *
     * Sind die Positionen bereits geladen, wird über sie summiert. Die eigene
     * Abfrage hätte den Eager-Load sonst wirkungslos gemacht und wäre einmal
     * pro Archiv gelaufen.
     *
     * Ohne Zwischenspeicher. Vorher lag die Summe in einer Objekt-Eigenschaft,
     * die nichts je wieder geleert hat - auch `load()` nicht: Wer die Positionen
     * nachlud und erneut fragte, bekam die Zahl von vorher. Der Zwischenspeicher
     * kaufte dabei nichts ein, denn im einzigen Pfad, der die Summe zweimal
     * braucht, sind die Positionen geladen und das Summieren kostet keine
     * Abfrage. Gespart hätte er nur dort, wo sie es nicht sind - und genau dort
     * war er falsch.
     */
    public function getTotalCount(): int
    {
        return $this->relationLoaded('lineItems')
            ? (int) $this->lineItems->sum('count')
            : (int) $this->lineItems()->sum('count');
    }
}
