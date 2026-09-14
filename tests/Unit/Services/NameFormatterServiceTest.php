<?php

declare(strict_types=1);

namespace Tests\Unit\Services;

use App\Services\NameFormatterService;
use PHPUnit\Framework\TestCase;

final class NameFormatterServiceTest extends TestCase
{
    public function testFormatsFirstNameFirstByDefault(): void
    {
        $service = new NameFormatterService();

        $this->assertSame('first_last', $service->getFormat());
        $this->assertSame('Anna Müller', $service->format('Anna', 'Müller'));
    }

    public function testFormatsLastNameFirstWhenConfigured(): void
    {
        $service = new NameFormatterService('last_first');

        $this->assertSame('Müller, Anna', $service->format('Anna', 'Müller'));
    }

    public function testFallsBackToDefaultForUnknownOrEmptyFormat(): void
    {
        $this->assertSame('first_last', NameFormatterService::normalizeFormat(null));
        $this->assertSame('first_last', NameFormatterService::normalizeFormat(''));
        $this->assertSame('first_last', NameFormatterService::normalizeFormat('kraut'));
        $this->assertSame('last_first', NameFormatterService::normalizeFormat(' LAST_FIRST '));
    }

    public function testHandlesMissingNameParts(): void
    {
        $service = new NameFormatterService('last_first');

        $this->assertSame('', $service->format(null, null));
        $this->assertSame('Anna', $service->format('Anna', ''));
        $this->assertSame('Müller', $service->format(null, 'Müller'));
    }

    public function testFormatsArraysAndObjects(): void
    {
        $service = new NameFormatterService('last_first');

        $this->assertSame(
            'Müller, Anna',
            $service->formatPerson(['first_name' => 'Anna', 'last_name' => 'Müller'])
        );
        $this->assertSame(
            'Müller, Anna',
            $service->formatPerson((object) ['first_name' => 'Anna', 'last_name' => 'Müller'])
        );
        $this->assertSame('', $service->formatPerson(null));
        $this->assertSame('', $service->formatPerson('Anna Müller'));
    }

    public function testOrderColumnsFollowFormat(): void
    {
        $this->assertSame(
            ['first_name', 'last_name'],
            (new NameFormatterService('first_last'))->orderColumns()
        );
        $this->assertSame(
            ['last_name', 'first_name'],
            (new NameFormatterService('last_first'))->orderColumns()
        );
    }

    /**
     * applyNameOrder() hängt die Spalten in der eingestellten Reihenfolge an und
     * gibt dieselbe Abfrage zurück, damit sich der Aufruf verketten lässt.
     *
     * Geprüft wird gegen einen Mitschnitt statt gegen einen echten Builder: die
     * Methode soll nichts weiter tun, als orderBy() der Reihe nach aufzurufen, und
     * genau das hält der Mitschnitt fest - ohne Datenbank.
     */
    public function testApplyNameOrderAddsColumnsInConfiguredOrder(): void
    {
        $recorder = new class {
            /** @var list<string> */
            public array $ordered = [];

            public function orderBy(string $column): self
            {
                $this->ordered[] = $column;

                return $this;
            }
        };

        $returned = (new NameFormatterService('last_first'))->applyNameOrder($recorder);

        $this->assertSame(['last_name', 'first_name'], $recorder->ordered);
        $this->assertSame($recorder, $returned);
    }

    public function testApplyNameOrderFollowsTheFirstLastFormat(): void
    {
        $recorder = new class {
            /** @var list<string> */
            public array $ordered = [];

            public function orderBy(string $column): self
            {
                $this->ordered[] = $column;

                return $this;
            }
        };

        (new NameFormatterService('first_last'))->applyNameOrder($recorder);

        $this->assertSame(['first_name', 'last_name'], $recorder->ordered);
    }
}
