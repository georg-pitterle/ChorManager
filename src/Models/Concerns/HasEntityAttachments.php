<?php

declare(strict_types=1);

namespace App\Models\Concerns;

use App\Models\Attachment;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Die Anhänge einer Entität, angebunden über `entity_type`/`entity_id`.
 *
 * Projekt, Aufgabe, Lied, Sponsor, Vereinbarung und Buchung hatten dieselben
 * zwei Zeilen sechsmal im Baum stehen - unterschiedlich war allein der
 * `entity_type`. Gleiche Begründung wie bei HasEntityComments: Eine Regel, die
 * sechsmal dasteht, wird irgendwann an fünf Stellen gepflegt.
 *
 * Voraussetzung ist eine Konstante `ENTITY_TYPE` auf dem Modell; darüber wacht
 * tests/Unit/Models/EntityRelationTraitsTest.
 *
 * Die Beziehung lädt nur die Metadaten, nie `file_content`. Jede Übersicht
 * erreicht die Anhänge über diese Beziehung, und ein `select *` zog dort jeden
 * BLOB durch den Speicher - im Repertoire mit rund 100 MB Liedanhängen bis zum
 * Speicherlimit und einer leeren 500-Seite. Den Inhalt holen die Stellen, die
 * ihn ausliefern, mit einer eigenen Abfrage
 * (tests/Feature/AttachmentRelationBlobLoadFeatureTest).
 */
trait HasEntityAttachments
{
    public function attachments(): HasMany
    {
        return $this->hasMany(Attachment::class, 'entity_id', 'id')
            ->select(array_map(
                static fn (string $column): string => 'attachments.' . $column,
                Attachment::METADATA_COLUMNS
            ))
            ->where('entity_type', static::ENTITY_TYPE);
    }
}
