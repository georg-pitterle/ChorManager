<?php

declare(strict_types=1);

namespace Tests\Unit\Util;

use App\Util\SessionConfig;
use PHPUnit\Framework\TestCase;

/**
 * Eine Sitzung nur für Anfragen, die schon zu einer gehören.
 *
 * PHP legt bei `session_start()` sofort eine Sitzungsdatei an und schickt ein
 * Set-Cookie - auch an Absender, die beides nie wieder vorzeigen: der
 * Noten-Ordner über WebDAV, die Rückmeldungen der Mailprovider, Collabora über
 * WOPI, der Token-Endpunkt des OIDC-Providers. Seit die Leerlaufgrenze bei acht
 * Stunden liegt, bleibt jede davon acht Stunden liegen.
 */
final class SessionConfigKnownSessionTest extends TestCase
{
    /** @var array<string, mixed> */
    private array $originalCookies = [];

    protected function setUp(): void
    {
        parent::setUp();

        $this->originalCookies = $_COOKIE;
        $this->discardSession();
        SessionConfig::resetReleased();
    }

    protected function tearDown(): void
    {
        $this->discardSession();
        $_COOKIE = $this->originalCookies;
        SessionConfig::resetReleased();

        parent::tearDown();
    }

    /**
     * Eine geschlossene Sitzung lässt ihre Kennung im Prozess stehen, und
     * `carriesSessionId()` zählt die mit. Ohne dieses Aufräumen entschiede die
     * Testreihenfolge über das Ergebnis - im parallelen Lauf liegen mehrere
     * Testklassen in einem Prozess.
     */
    private function discardSession(): void
    {
        if (session_status() !== PHP_SESSION_ACTIVE && session_id() !== '') {
            @session_start();
        }

        if (session_status() === PHP_SESSION_ACTIVE) {
            $_SESSION = [];
            @session_destroy();
        }

        @session_id('');
        $_SESSION = [];
        unset($_COOKIE[(string) session_name()]);
    }

    public function testARequestWithoutAnySessionCookieStartsNoSession(): void
    {
        $this->assertFalse(SessionConfig::ensureStartedForKnownSession());
        $this->assertSame(PHP_SESSION_NONE, session_status());
        $this->assertSame('', session_id());
    }

    public function testARequestThatBringsTheSessionCookieStartsTheSession(): void
    {
        $_COOKIE[(string) session_name()] = 'abcdef0123456789abcdef0123456789';

        $this->assertTrue(SessionConfig::ensureStartedForKnownSession());
        $this->assertSame(PHP_SESSION_ACTIVE, session_status());
    }

    public function testAnEmptySessionCookieDoesNotCount(): void
    {
        $_COOKIE[(string) session_name()] = '';

        $this->assertFalse(SessionConfig::ensureStartedForKnownSession());
        $this->assertSame(PHP_SESSION_NONE, session_status());
    }

    /**
     * Eine im Prozess gesetzte Kennung zählt ebenfalls: Dann hat jemand sie
     * absichtlich gesetzt, und die Sitzung ist gemeint.
     */
    public function testAnExplicitlySetSessionIdCounts(): void
    {
        session_id('abcdef0123456789abcdef0123456789');

        $this->assertTrue(SessionConfig::ensureStartedForKnownSession());
        $this->assertSame(PHP_SESSION_ACTIVE, session_status());
    }

    public function testAnAlreadyActiveSessionStaysActive(): void
    {
        session_start();
        $sessionId = session_id();

        $this->assertTrue(SessionConfig::ensureStartedForKnownSession());
        $this->assertSame($sessionId, session_id());
    }

    /**
     * Nach der Freigabe durch AfterResponse sind die Kopfzeilen längst draußen -
     * dieselbe Sperre wie bei `ensureStarted()`.
     */
    public function testNothingStartsAfterTheRequestWasReleased(): void
    {
        $_COOKIE[(string) session_name()] = 'abcdef0123456789abcdef0123456789';
        SessionConfig::markReleased();

        $this->assertFalse(SessionConfig::ensureStartedForKnownSession());
        $this->assertSame(PHP_SESSION_NONE, session_status());
    }
}
