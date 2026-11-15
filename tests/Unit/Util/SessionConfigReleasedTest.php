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

    /**
     * Derselbe Nachweis, aber mit der echten Freigabe aus
     * `AfterResponse::forCurrentRequest()` statt einer eigenen Abschrift im Test.
     *
     * Der Test oben setzt den Vermerk in seiner eigenen Freigabe-Funktion und
     * belegt damit nur, dass `runPending()` sie aufruft. Die echte Funktion setzte
     * ihn ausschließlich neben `fastcgi_finish_request()` - unter einer
     * Betriebsart ohne diese Funktion (eingebauter Entwicklungsserver, mod_php)
     * wurde die Sitzung also geschlossen, ohne dass es jemand vermerkte, und ein
     * spätes `ensureStarted()` hätte sie samt Sperre wieder geöffnet. Genau das
     * hält dieser Test fest: Die Freigabe gilt in jeder Betriebsart.
     */
    public function testRealReleaseMarksTheSessionAsReleasedWithoutFastcgi(): void
    {
        if (function_exists('fastcgi_finish_request')) {
            self::markTestSkipped('Nur ohne fastcgi_finish_request nachweisbar.');
        }

        $afterResponse = AfterResponse::forCurrentRequest();
        $afterResponse->defer(static function (): void {
        });
        $afterResponse->runPending();

        $this->assertFalse(SessionConfig::ensureStarted());
        $this->assertSame(PHP_SESSION_NONE, session_status());
    }
}
