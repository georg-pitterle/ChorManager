<?php

declare(strict_types=1);

namespace Tests\Unit\Models;

use App\Models\Event;
use App\Models\Newsletter;
use App\Models\NewsletterTemplate;
use Illuminate\Database\Eloquent\Model;
use PHPUnit\Framework\TestCase;
use Tests\Unit\Bootstrap;

/**
 * Die Zeilen einer Zielgruppe kommen in fester Reihenfolge - aufsteigend nach
 * `id`, so wie sie angelegt wurden.
 *
 * `HasAudienceConditionSets::audienceConditionSets()` hat zwei Wege zu denselben
 * Zeilen: die vorab geladene Beziehung und das Nachladen über `conditions`. Das
 * Nachladen sortierte von jeher nach `id`, die vorab geladene Beziehung gar
 * nicht. Welcher Weg genommen wird, entscheidet der Aufrufer - EventController
 * lädt vor (`$events->load('audienceFilters.conditions')`), EventAudienceService
 * verwirft die Beziehung mit `unsetRelation()` und lädt nach. Für dieselbe
 * Zielgruppe konnte der Beschreibungstext aus `summarizeSets()` damit in zwei
 * verschiedenen Reihenfolgen entstehen, je nachdem, über welche Seite man kam.
 *
 * Dass die Datenbank die Zeilen in der Praxis meist ohnehin nach `id` liefert,
 * trägt nicht: Ohne `ORDER BY` ist die Reihenfolge nicht zugesichert, und ein
 * anderer Ausführungsplan kehrt sie um, ohne dass sich eine Zeile geändert hat.
 * Geprüft wird deshalb die Abfrage selbst, nicht das, was eine einzelne
 * Installation gerade zurückgibt.
 */
final class AudienceFilterRelationOrderingTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        Bootstrap::setupTestDatabase();
    }

    /**
     * @return array<string, array{class-string<Model>}>
     */
    public static function audienceOwnerProvider(): array
    {
        return [
            'Event' => [Event::class],
            'Newsletter' => [Newsletter::class],
            'NewsletterTemplate' => [NewsletterTemplate::class],
        ];
    }

    /**
     * @param class-string<Model> $class
     */
    #[\PHPUnit\Framework\Attributes\DataProvider('audienceOwnerProvider')]
    public function testDieZielgruppenZeilenKommenNachIdSortiert(string $class): void
    {
        $owner = new $class();
        $orders = $owner->audienceFilters()->getQuery()->getQuery()->orders ?? [];

        self::assertSame(
            [['column' => 'audience_filters.id', 'direction' => 'asc']],
            $orders,
            sprintf(
                '%s::audienceFilters() muss nach audience_filters.id sortieren, sonst steht die '
                    . 'vorab geladene Zielgruppe anders herum als die nachgeladene.',
                $class
            )
        );
    }
}
