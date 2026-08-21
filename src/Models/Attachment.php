<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Attachment extends Model
{
    protected $table = 'attachments';
    public $timestamps = false;

    // Use string type for created_at if we aren't using Laravel's automatic timestamping, or just let Eloquent handle it
    protected $fillable = [
        'entity_type',
        'entity_id',
        'filename',
        'original_name',
        'mime_type',
        'file_size',
        'file_content',
        'created_at'
    ];

    protected $casts = [
        'created_at' => 'datetime',
    ];

    /**
     * Rueckrichtung des polymorphen Anhangs auf das Lied. entity_type steht auf
     * der Anhang-Zeile selbst; als Relations-Bedingung landete die Spalte in der
     * Abfrage auf songs und liess die Relation mit einem SQL-Fehler auflaufen.
     * Die Unterscheidung gehoert deshalb vor die Abfrage.
     */
    public function song()
    {
        $relation = $this->belongsTo(Song::class, 'entity_id', 'id');

        if ($this->entity_type !== 'song') {
            $relation->whereRaw('1 = 0');
        }

        return $relation;
    }
}
