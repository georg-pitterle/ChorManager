<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Services\Audience\AudienceFilterService;

/**
 * Legt Zielgruppen-Filter für einen Besitzer an. Jede übergebene
 * Bedingungsmenge wird ein eigener Filter; [] heißt „alle Mitglieder“.
 */
trait AudienceFixtures
{
    /**
     * @param array<string, list<int>> ...$conditionSets
     */
    protected function giveAudience(string $ownerColumn, int $ownerId, array ...$conditionSets): void
    {
        $service = new AudienceFilterService();
        foreach ($conditionSets as $conditions) {
            $service->create($conditions, $ownerColumn, $ownerId);
        }
    }

    /**
     * Empfängerauswahl einer Vorlage als vergleichbare Schlüssel:
     * "kategorie:kennung" je Wert einer Zeile, "event:kennung" je Termin.
     *
     * @return list<string>
     */
    protected function templateAudienceKeys(\App\Models\NewsletterTemplate $template): array
    {
        $audience = (new \App\Persistence\NewsletterTemplatePersistence())->audienceOf($template);
        $keys = [];
        foreach ($audience['sets'] as $set) {
            foreach ($set as $category => $ids) {
                foreach ($ids as $id) {
                    $keys[] = $category . ':' . $id;
                }
            }
        }
        foreach ($audience['event_ids'] as $id) {
            $keys[] = 'event:' . $id;
        }

        return $keys;
    }

    /**
     * Gibt einem Termin die Zielgruppe "Alle Mitglieder". Ein Termin ohne
     * Zielgruppen-Zeile trifft niemanden; vor der Umstellung auf Filter galt er
     * stillschweigend für alle.
     */
    protected function openToEveryone(\App\Models\Event $event): \App\Models\Event
    {
        $this->giveAudience('event_id', (int) $event->id, []);

        return $event;
    }
}
