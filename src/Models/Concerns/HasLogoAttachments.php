<?php

declare(strict_types=1);

namespace App\Models\Concerns;

use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Logo-Dateien einer Entität, getrennt von ihren übrigen Anhängen.
 *
 * Voraussetzung sind HasEntityAttachments und eine Konstante
 * `LOGO_ENTITY_TYPE` auf dem Modell. Eine eigene Trait und keine Methode am
 * Sponsor: Beziehungen über entity_id stehen nur in den Concerns, darüber
 * wacht tests/Unit/Models/EntityRelationTraitsTest.
 */
trait HasLogoAttachments
{
    /**
     * Die Logo-Dateien, alphabetisch. Nur die Metadaten, wie bei
     * attachments(): den Inhalt holt, wer ihn ausliefert.
     */
    public function logos(): HasMany
    {
        return $this->attachmentsOfType(static::LOGO_ENTITY_TYPE)->orderBy('original_name');
    }
}
