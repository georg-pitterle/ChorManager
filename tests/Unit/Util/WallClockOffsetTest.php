<?php

declare(strict_types=1);

namespace Tests\Unit\Util;

use App\Util\WallClockOffset;
use Carbon\Carbon;
use PHPUnit\Framework\TestCase;

/**
 * Abstand zweier Zeitpunkte nach der Uhr an der Wand, nicht nach verstrichenen
 * Sekunden. "Zwei Tage vorher, 19:00" bleibt 19:00, auch wenn dazwischen die
 * Zeitumstellung liegt.
 */
final class WallClockOffsetTest extends TestCase
{
    private string $previousTimezone;

    protected function setUp(): void
    {
        parent::setUp();
        $this->previousTimezone = date_default_timezone_get();
        date_default_timezone_set('Europe/Vienna');
    }

    protected function tearDown(): void
    {
        date_default_timezone_set($this->previousTimezone);
        parent::tearDown();
    }

    public function testOffsetIsMeasuredOnTheWallClock(): void
    {
        $this->assertSame(-2 * 86400, WallClockOffset::seconds('2030-10-28 19:00', '2030-10-26 19:00'));
        $this->assertSame(-5400, WallClockOffset::seconds('2030-10-21 19:00', '2030-10-21 17:30'));
    }

    public function testOffsetAcrossTheAutumnChangeKeepsTheTimeOfDay(): void
    {
        // Umstellung am 27.10.2030: zwischen den beiden Zeitpunkten liegen 49 Stunden.
        $deadline = WallClockOffset::shift(Carbon::parse('2030-10-28 19:00'), -2 * 86400);

        $this->assertSame('2030-10-26 19:00', $deadline->format('Y-m-d H:i'));
        $this->assertSame('Europe/Vienna', $deadline->getTimezone()->getName());
    }

    public function testOffsetAcrossTheSpringChangeKeepsTheTimeOfDay(): void
    {
        // Umstellung am 31.03.2030: zwischen den beiden Zeitpunkten liegen 47 Stunden.
        $deadline = WallClockOffset::shift('2030-04-01 19:00', -2 * 86400);

        $this->assertSame('2030-03-30 19:00', $deadline->format('Y-m-d H:i'));
    }

    public function testShiftDoesNotTouchItsInput(): void
    {
        $base = Carbon::parse('2030-10-28 19:00');
        WallClockOffset::shift($base, -86400);

        $this->assertSame('2030-10-28 19:00', $base->format('Y-m-d H:i'));
    }
}
