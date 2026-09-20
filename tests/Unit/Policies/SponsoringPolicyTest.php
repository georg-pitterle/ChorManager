<?php

declare(strict_types=1);

namespace Tests\Unit\Policies;

use App\Policies\SponsoringPolicy;
use PHPUnit\Framework\TestCase;

/**
 * Deckt die Projektprüfung der Sponsoring-Policy ab. Die Entscheidung fällt für
 * eine nicht vorhandene Kennung ohne Datenbankzugriff, deshalb reicht hier ein
 * reiner Unit-Test.
 */
final class SponsoringPolicyTest extends TestCase
{
    /** @var array<string, mixed> */
    private array $sessionBackup = [];

    protected function setUp(): void
    {
        parent::setUp();
        $this->sessionBackup = $_SESSION ?? [];
        $_SESSION = [];
    }

    protected function tearDown(): void
    {
        $_SESSION = $this->sessionBackup;
        parent::tearDown();
    }

    public function testWithoutContributionRightNoProjectIsAllowed(): void
    {
        $_SESSION = ['user_id' => 7];

        $policy = new SponsoringPolicy();

        $this->assertFalse($policy->canUseProject(null));
        $this->assertFalse($policy->canUseProject(5));
    }

    /**
     * "Kein Projekt" ist eine allgemeine Anfrage ohne Projektbezug und bleibt
     * erlaubt - der Controller schickt dafür null.
     */
    public function testAGeneralRequestWithoutProjectIsAllowed(): void
    {
        $_SESSION = ['user_id' => 7, 'can_create_own_sponsorships' => true];

        $this->assertTrue((new SponsoringPolicy())->canUseProject(null));
    }

    /**
     * Eine Kennung, die kein Projekt sein kann, wird abgewiesen statt als
     * "kein Projekt" durchgewinkt. Sonst reicht SponsorshipController::create()
     * den Wert unverändert an Sponsorship::create() weiter, der Fremdschlüssel
     * auf projects weist ihn ab, und die Eingabe kommt als nichtssagendes
     * "Fehler beim Anlegen" zurück statt als Hinweis auf das Projekt.
     */
    public function testAnImpossibleProjectIdIsRejectedInsteadOfTreatedAsNoProject(): void
    {
        $_SESSION = ['user_id' => 7, 'can_create_own_sponsorships' => true];
        $contributor = new SponsoringPolicy();

        $this->assertFalse($contributor->canUseProject(0));
        $this->assertFalse($contributor->canUseProject(-5));

        // Auch das Vollrecht prüft die Kennung - es darf jedes Projekt wählen,
        // aber keines, das es nicht geben kann.
        $_SESSION = ['user_id' => 7, 'can_manage_sponsoring' => true];
        $manager = new SponsoringPolicy();

        $this->assertFalse($manager->canUseProject(0));
        $this->assertFalse($manager->canUseProject(-5));
        $this->assertTrue($manager->canUseProject(null));
    }
}
