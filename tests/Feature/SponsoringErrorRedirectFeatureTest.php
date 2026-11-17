<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Controllers\SponsoringContactController;
use App\Controllers\SponsorshipController;
use App\Models\Sponsor;
use App\Models\Sponsorship;
use App\Policies\SponsoringPolicy;
use App\Util\SponsoringUrl;
use Slim\Views\Twig;
use PHPUnit\Framework\TestCase;
use Tests\Unit\Bootstrap;

/**
 * Fehlerwege des Sponsorings landen auf einer Seite, die ihre Meldung zeigt.
 *
 * Gefunden im rotierenden Review (Lauf 77, src/Controllers): Die Rückwege
 * setzten den Pfad als `'/sponsoring/sponsors/' . $sponsorId` zusammen. Fehlte
 * `sponsor_id` in der Anfrage - genau der Fall, für den
 * `SponsorshipController::update()` seinen Fang auf ModelNotFoundException
 * hat -, hieß das Ziel `/sponsoring/sponsors/0`. Dort wirft
 * `SponsorController::detail()` seinerseits ModelNotFoundException, die
 * Anwendung zeigt `errors/404.twig`, und weil diese Seite keine Meldungen
 * ausgibt, verschwand die gerade gesetzte Begründung ungesehen.
 */
final class SponsoringErrorRedirectFeatureTest extends TestCase
{
    use TestHttpHelpers;
    use BuildsControllers;

    protected function setUp(): void
    {
        parent::setUp();
        $_SESSION = [];
        $_SESSION['user_id'] = 1;
        $_SESSION['can_manage_sponsoring'] = true;
        Bootstrap::setupTestDatabase();
    }

    protected function tearDown(): void
    {
        $_SESSION = [];
        parent::tearDown();
    }

    public function testSponsorDetailPathFallsBackToTheListWithoutAUsableSponsor(): void
    {
        $this->assertSame('/sponsoring/sponsors/7', SponsoringUrl::sponsorDetail(7));
        $this->assertSame('/sponsoring/sponsors', SponsoringUrl::sponsorDetail(0));
        $this->assertSame('/sponsoring/sponsors', SponsoringUrl::sponsorDetail(null));
    }

    public function testCreateWithoutSponsorLandsOnTheSponsorListInsteadOfSponsorZero(): void
    {
        $controller = $this->sponsorshipController(['attachments' => $this->attachmentService()]);

        $response = $controller->create(
            $this->makeRequest('POST', '/sponsoring/sponsorships', ['amount' => '100']),
            $this->makeResponse()
        );

        $this->assertRedirect($response, '/sponsoring/sponsors');
        $this->assertSame('Sponsor ist ein Pflichtfeld.', $_SESSION['error'] ?? null);
    }

    public function testUpdateOfAnAlreadyDeletedSponsorshipKeepsItsMessageReadable(): void
    {
        $controller = $this->sponsorshipController(['attachments' => $this->attachmentService()]);
        $goneId = ((int) (Sponsorship::query()->max('id') ?? 0)) + 5000;

        $response = $controller->update(
            $this->makeRequest('POST', '/sponsoring/sponsorships/' . $goneId, ['amount' => '100']),
            $this->makeResponse(),
            ['id' => (string) $goneId]
        );

        $this->assertRedirect($response, '/sponsoring/sponsors');
        $this->assertStringContainsString('nicht gefunden', (string) ($_SESSION['error'] ?? ''));
    }

    public function testDeleteOfAnAlreadyDeletedSponsorshipKeepsItsMessageReadable(): void
    {
        $controller = $this->sponsorshipController(['attachments' => $this->attachmentService()]);
        $goneId = ((int) (Sponsorship::query()->max('id') ?? 0)) + 5000;

        $response = $controller->delete(
            $this->makeRequest('POST', '/sponsoring/sponsorships/' . $goneId . '/delete', []),
            $this->makeResponse(),
            ['id' => (string) $goneId]
        );

        $this->assertRedirect($response, '/sponsoring/sponsors');
        $this->assertStringContainsString('nicht gefunden', (string) ($_SESSION['error'] ?? ''));
    }

    public function testContactWithoutSponsorLandsOnTheSponsorList(): void
    {
        $controller = new SponsoringContactController($this->createStub(Twig::class), new SponsoringPolicy($_SESSION));

        $response = $controller->create(
            $this->makeRequest('POST', '/sponsoring/contacts', ['note' => 'Angerufen']),
            $this->makeResponse()
        );

        $this->assertRedirect($response, '/sponsoring/sponsors');
        $this->assertNotSame('', (string) ($_SESSION['error'] ?? ''));
    }

    /**
     * Der gute Weg bleibt, wie er war: Mit Sponsor führt der Rückweg auf dessen
     * Seite, nicht auf die Liste.
     */
    public function testKnownSponsorStillRedirectsToItsOwnPage(): void
    {
        $sponsor = Sponsor::create(['name' => 'Rückwegtest ' . bin2hex(random_bytes(4))]);
        $controller = $this->sponsorshipController(['attachments' => $this->attachmentService()]);

        try {
            $response = $controller->create(
                $this->makeRequest('POST', '/sponsoring/sponsorships', [
                    'sponsor_id' => (string) $sponsor->id,
                    'amount' => 'keine Zahl',
                ]),
                $this->makeResponse()
            );

            $this->assertRedirect($response, '/sponsoring/sponsors/' . $sponsor->id);
            $this->assertSame(SponsorshipController::AMOUNT_ERROR, $_SESSION['error'] ?? null);
        } finally {
            Sponsorship::where('sponsor_id', $sponsor->id)->delete();
            $sponsor->delete();
        }
    }
}
