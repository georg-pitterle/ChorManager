<?php

declare(strict_types=1);

namespace Tests\Unit\Util;

use App\Util\AfterResponse;
use PHPUnit\Framework\TestCase;

final class AfterResponseTest extends TestCase
{
    /** @var list<string> */
    private array $calls = [];

    public function testDeferredWorkWaitsUntilTheResponseIsOut(): void
    {
        $afterResponse = $this->afterResponse();

        $afterResponse->defer(function (): void {
            $this->calls[] = 'task';
        });

        $this->assertSame([], $this->calls);
    }

    /**
     * Die Reihenfolge ist der ganze Zweck: Erst wird die Sitzung geschrieben
     * und die Verbindung zum Browser geschlossen, dann gearbeitet. Andersherum
     * hielte ein langsamer Mailserver wieder die Sitzung fest.
     */
    public function testRequestIsReleasedBeforeTheWorkStarts(): void
    {
        $afterResponse = $this->afterResponse();
        $afterResponse->defer(function (): void {
            $this->calls[] = 'first';
        });
        $afterResponse->defer(function (): void {
            $this->calls[] = 'second';
        });

        $afterResponse->runPending();

        $this->assertSame(['release', 'first', 'second'], $this->calls);
    }

    public function testEachTaskRunsOnlyOnce(): void
    {
        $afterResponse = $this->afterResponse();
        $afterResponse->defer(function (): void {
            $this->calls[] = 'task';
        });

        $afterResponse->runPending();
        $afterResponse->runPending();

        $this->assertSame(['release', 'task'], $this->calls);
    }

    public function testWithoutWorkNothingIsReleased(): void
    {
        $this->afterResponse()->runPending();

        $this->assertSame([], $this->calls);
    }

    private function afterResponse(): AfterResponse
    {
        return new AfterResponse(function (): void {
            $this->calls[] = 'release';
        }, false);
    }
}
