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
}
