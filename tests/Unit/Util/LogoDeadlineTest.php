<?php

declare(strict_types=1);

namespace Tests\Unit\Util;

use App\Util\LogoDeadline;
use Carbon\Carbon;
use PHPUnit\Framework\TestCase;

/**
 * Welche Frist für das Logo einer Vereinbarung gilt.
 *
 * Fürs Plakat muss das Logo viel früher da sein als für Homepage und Social
 * Media. Die Fristen stehen am Projekt, die Vereinbarung sagt, wofür sie das
 * Logo braucht.
 */
final class LogoDeadlineTest extends TestCase
{
    private Carbon $print;
    private Carbon $online;

    protected function setUp(): void
    {
        parent::setUp();
        $this->print = Carbon::parse('2026-06-10');
        $this->online = Carbon::parse('2026-07-02');
    }

    public function testWithoutLogoNeedThereIsNoDeadline(): void
    {
        $this->assertNull(LogoDeadline::for(false, false, $this->print, $this->online));
    }

    public function testPrintNeedUsesThePrintDeadline(): void
    {
        $deadline = LogoDeadline::for(true, true, $this->print, $this->online);

        $this->assertSame('2026-06-10', $deadline['date']?->format('Y-m-d'));
        $this->assertSame('print', $deadline['kind']);
    }

    public function testOnlineNeedUsesTheOnlineDeadline(): void
    {
        $deadline = LogoDeadline::for(false, true, $this->print, $this->online);

        $this->assertSame('2026-07-02', $deadline['date']?->format('Y-m-d'));
        $this->assertSame('online', $deadline['kind']);
    }

    public function testPrintNeedWithoutPrintDeadlineFallsBackToTheOnlineDeadline(): void
    {
        $deadline = LogoDeadline::for(true, false, null, $this->online);

        $this->assertSame('2026-07-02', $deadline['date']?->format('Y-m-d'));
        $this->assertSame('online', $deadline['kind']);
    }

    public function testWithoutAnyDateTheKindStaysAndTheDateIsEmpty(): void
    {
        $this->assertSame(['date' => null, 'kind' => 'print'], LogoDeadline::for(true, false, null, null));
        $this->assertSame(['date' => null, 'kind' => 'online'], LogoDeadline::for(false, true, null, null));
    }

    public function testOnlineNeedIgnoresThePrintDeadline(): void
    {
        $deadline = LogoDeadline::for(false, true, $this->print, null);

        $this->assertSame(['date' => null, 'kind' => 'online'], $deadline);
    }
}
