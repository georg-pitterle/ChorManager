<?php

declare(strict_types=1);

namespace Tests\Unit\Util;

use App\Util\PasswordHasher;
use PHPUnit\Framework\TestCase;

/**
 * Ein bcrypt-Hash mit dem Standardaufwand kostet im Container rund 160 ms. Die Suite
 * legt in setUp() reihenweise Personen an und verbrachte damit rund ein Viertel ihrer
 * Laufzeit im Hashen von Passwörtern, die kein Test je prüft. Im Testlauf sinkt der
 * Aufwand deshalb auf das Minimum - überall sonst bleibt er, wie er war.
 */
final class PasswordHasherTest extends TestCase
{
    public function testAHashedPasswordCanBeVerified(): void
    {
        $hash = PasswordHasher::hash('geheim');

        $this->assertTrue(password_verify('geheim', $hash));
        $this->assertFalse(password_verify('etwas anderes', $hash));
    }

    public function testTwoHashesOfTheSamePasswordDiffer(): void
    {
        $this->assertNotSame(PasswordHasher::hash('geheim'), PasswordHasher::hash('geheim'));
    }

    /**
     * Der Testlauf setzt APP_ENV=test, deshalb greift hier der abgesenkte Aufwand.
     */
    public function testTheTestRunUsesTheReducedCost(): void
    {
        $this->assertSame('test', $_ENV['APP_ENV'] ?? null);
        $this->assertSame(PasswordHasher::TEST_COST, PasswordHasher::costForCurrentEnvironment());
    }

    /**
     * Außerhalb des Testlaufs darf nichts den Aufwand senken. Ein abgesenkter Aufwand im
     * Betrieb wäre ein Sicherheitsmangel, der niemandem auffällt.
     */
    public function testOutsideTheTestRunTheDefaultCostStands(): void
    {
        $this->assertNull(PasswordHasher::costForEnvironment('production'));
        $this->assertNull(PasswordHasher::costForEnvironment('development'));
        $this->assertSame(PasswordHasher::TEST_COST, PasswordHasher::costForEnvironment('test'));
    }
}
