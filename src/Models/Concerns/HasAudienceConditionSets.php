<?php

declare(strict_types=1);

namespace App\Models\Concerns;

use App\Models\AudienceFilter;

/**
 * Die Bedingungsmengen der eigenen Zielgruppe lesen - eine je Filterzeile.
 *
 * Event und Newsletter beantworteten dieselbe Frage vorher an zwei Stellen
 * wortgleich: Event im Model selbst, Newsletter eingebaut in
 * NewsletterRecipientService::audienceOf(). Damit stand die Regel "eine vorab
 * geladene Beziehung bevorzugen, sonst mit `conditions` nachladen" doppelt, und
 * wer die eine Fassung anfasste, musste die andere mitnehmen.
 *
 * Voraussetzung ist eine Beziehung `audienceFilters()` auf AudienceFilter.
 */
trait HasAudienceConditionSets
{
    /**
     * Bedingungsmengen der Zielgruppe, eine je Zeile. Nutzt eine vorab geladene
     * Beziehung (`with('audienceFilters.conditions')`).
     *
     * Ohne sie wird mit `conditions` nachgeladen - ein `unsetRelation()` nach
     * dem Austausch der Zielgruppe landet deshalb wieder beim gespeicherten
     * Stand und nicht beim eben verworfenen.
     *
     * @return list<array<string, list<int>>>
     */
    public function audienceConditionSets(): array
    {
        $filters = $this->relationLoaded('audienceFilters')
            ? $this->audienceFilters
            : $this->audienceFilters()->with('conditions')->orderBy('id')->get();

        return $filters->map(static fn (AudienceFilter $filter): array => $filter->conditionSet())->values()->all();
    }
}
