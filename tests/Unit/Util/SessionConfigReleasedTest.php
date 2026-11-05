<?php

declare(strict_types=1);

namespace Tests\Unit\Util;

use App\Util\AfterResponse;
use App\Util\SessionConfig;
use PHPUnit\Framework\TestCase;

/**
 * Nach der Antwort ist die Sitzung freigegeben und die Verbindung zu. Ein spätes
 * `ensureStarted()` (etwa beim Bauen von Twig in einer nachgelagerten Aufgabe)
 * darf `session_start()` dann nicht mehr aufrufen: PHP warnte bei jedem Mal mit
 * "Session cannot be started after headers have already been sent".
 */
final class SessionConfigReleasedTest extends TestCase
{
    protected function setUp(): void
    {
        if (session_status() === PHP_SESSION_ACTIVE) {
            session_write_close();
        }
        SessionConfig::resetReleased();
    }

    protected function tearDown(): void
    {
        SessionConfig::resetReleased();
    }

    public function testEnsureStartedDoesNotStartASessionAfterRelease(): void
    {
        SessionConfig::markReleased();

        $this->assertFalse(SessionConfig::ensureStarted());
        $this->assertSame(PHP_SESSION_NONE, session_status());
    }

    public function testReleasingTheRequestMarksTheSessionAsReleased(): void
    {
        $afterResponse = new AfterResponse(static function (): void {
            SessionConfig::markReleased();
        }, false);
        $afterResponse->defer(static function (): void {
        });
        $afterResponse->runPending();

        $this->assertFalse(SessionConfig::ensureStarted());
    }
}
