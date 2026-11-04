<?php

declare(strict_types=1);

namespace Tests\Unit\Services;

use App\Models\AudienceFilter;
use App\Services\Audience\AudienceOwnerService;
use App\Services\EventAudienceService;
use App\Services\NewsletterRecipientService;
use App\Services\PollAudienceService;
use PHPUnit\Framework\TestCase;
use ReflectionClass;
use ReflectionMethod;

/**
 * Termin, Umfrage und Newsletter besitzen je eine Zielgruppe, und jeder der drei
 * Dienste trug dafür denselben Unterbau: Formular lesen, Filter des Besitzers
 * ersetzen, Mitglieder auflösen. `readRows()` stand wortgleich dreimal da.
 *
 * Das ist der Rechte-Pfad. Wer die Regel ändert - etwa "mindestens eine Zeile" -,
 * musste an drei Stellen daran denken, und eine vergessene fiel nirgends auf,
 * weil jede Fassung für sich richtig aussah. Dieser Test hält fest, dass es bei
 * einer Stelle bleibt, auch wenn ein vierter Besitzer dazukommt.
 */
final class AudienceOwnerServicesShareOneBaseTest extends TestCase
{
    /** @return list<array{0: class-string<AudienceOwnerService>}> */
    public static function serviceProvider(): array
    {
        return [
            [EventAudienceService::class],
            [PollAudienceService::class],
            [NewsletterRecipientService::class],
        ];
    }

    /**
     * @param class-string<AudienceOwnerService> $service
     */
    #[\PHPUnit\Framework\Attributes\DataProvider('serviceProvider')]
    public function testEveryAudienceOwnerServiceUsesTheSharedBase(string $service): void
    {
        $this->assertTrue(
            is_subclass_of($service, AudienceOwnerService::class),
            $service . ' muss den gemeinsamen Unterbau nutzen, statt ihn abzuschreiben.'
        );
    }

    /**
     * @param class-string<AudienceOwnerService> $service
     */
    #[\PHPUnit\Framework\Attributes\DataProvider('serviceProvider')]
    public function testTheOwnerColumnIsOneTheFilterServiceKnows(string $service): void
    {
        // Seit PHP 8.1 erreicht Reflection eine geschützte Methode ohne
        // setAccessible(); der Aufruf ist seit 8.5 ausdrücklich veraltet.
        $this->assertContains(
            (new ReflectionMethod($service, 'ownerColumn'))->invoke(new $service()),
            AudienceFilter::OWNER_COLUMNS,
            'Eine unbekannte Besitzer-Spalte trifft niemanden, statt laut zu scheitern.'
        );
    }

    /**
     * @param class-string<AudienceOwnerService> $service
     */
    #[\PHPUnit\Framework\Attributes\DataProvider('serviceProvider')]
    public function testNoServiceBringsItsOwnFormReading(string $service): void
    {
        $own = new ReflectionClass($service);

        foreach (['readRows', 'rowsFrom'] as $method) {
            if (!$own->hasMethod($method)) {
                continue;
            }

            $this->assertNotSame(
                $service,
                $own->getMethod($method)->getDeclaringClass()->getName(),
                sprintf('%s::%s() gehört in den gemeinsamen Unterbau, nicht hierher.', $service, $method)
            );
        }
    }

    /**
     * Die Pflicht auf mindestens eine Zeile steht genau einmal im Quelltext.
     * Ohne sie gäbe ein versehentlich geleertes Formular den Termin oder die
     * Umfrage für alle frei - der Satz darf deshalb nicht wieder in die
     * einzelnen Dienste wandern.
     */
    public function testTheMandatoryRowRuleExistsOnlyOnce(): void
    {
        $hits = [];
        foreach (glob(dirname(__DIR__, 3) . '/src/Services/{,Audience/}*.php', GLOB_BRACE) ?: [] as $file) {
            $source = (string) file_get_contents($file);
            if (str_contains($source, 'Bitte mindestens eine Zielgruppe angeben.')) {
                $hits[] = basename($file);
            }
        }

        $this->assertSame(['AudienceOwnerService.php'], $hits);
    }
}
