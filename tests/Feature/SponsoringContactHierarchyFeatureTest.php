<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Controllers\SponsorController;
use App\Models\Sponsor;
use App\Models\SponsoringContact;
use App\Models\Sponsorship;
use App\Policies\SponsoringPolicy;
use App\Util\SponsorshipStatus;
use PHPUnit\Framework\TestCase;
use Psr\Http\Message\ResponseInterface;
use Slim\Views\Twig;
use Tests\Unit\Bootstrap;

/**
 * Verhandelt wird je Projekt, deshalb hängt die Kontakthistorie an der
 * Vereinbarung und nicht mehr nur am Sponsor.
 */
class SponsoringContactHierarchyFeatureTest extends TestCase
{
    use TestHttpHelpers;

    protected function setUp(): void
    {
        parent::setUp();
        $_SESSION = [];
        Bootstrap::setupTestDatabase();
    }

    protected function tearDown(): void
    {
        $_SESSION = [];
        parent::tearDown();
    }

    public function testDetailViewLoadsContactsBelowTheirAgreement(): void
    {
        $_SESSION['can_manage_sponsoring'] = true;

        $sponsor = Sponsor::create(['name' => 'Hierarchietest ' . bin2hex(random_bytes(4))]);
        $sponsorship = Sponsorship::create([
            'sponsor_id' => $sponsor->id,
            'amount' => '100.00',
            'status' => SponsorshipStatus::REQUESTED,
        ]);

        $linked = SponsoringContact::create([
            'sponsor_id' => $sponsor->id,
            'sponsorship_id' => $sponsorship->id,
            'contact_date' => date('Y-m-d'),
            'type' => 'call',
            'summary' => 'Anfrage zum laufenden Projekt gestellt.',
            'follow_up_done' => 0,
        ]);

        // Ein allgemeiner Kontakt ohne Vereinbarung bleibt möglich - etwa ein
        // Dankschreiben nach der Saison.
        $general = SponsoringContact::create([
            'sponsor_id' => $sponsor->id,
            'contact_date' => date('Y-m-d'),
            'type' => 'letter',
            'summary' => 'Jahresrückblick versendet.',
            'follow_up_done' => 1,
        ]);

        try {
            $captured = [];
            $twig = $this->createStub(Twig::class);
            $twig->method('render')->willReturnCallback(
                function ($response, string $template, array $data) use (&$captured): ResponseInterface {
                    $captured = $data;
                    return $response;
                }
            );

            $controller = new SponsorController($twig, new SponsoringPolicy(), $this->logger()[0]);
            $controller->detail(
                $this->makeRequest('GET', '/sponsoring/sponsors/' . $sponsor->id),
                $this->makeResponse(),
                ['id' => (string) $sponsor->id]
            );

            $loadedSponsorship = $captured['sponsor']->sponsorships->firstOrFail();
            $contactIds = $loadedSponsorship->contacts->pluck('id')->all();

            $this->assertContains($linked->id, $contactIds);
            $this->assertNotContains($general->id, $contactIds);
            $this->assertCount(2, $captured['sponsor']->contacts);
        } finally {
            SponsoringContact::whereIn('id', [$linked->id, $general->id])->delete();
            $sponsorship->delete();
            $sponsor->delete();
        }
    }

    public function testDetailTemplateShowsContactsPerAgreementAndNamesTheProject(): void
    {
        $template = file_get_contents(dirname(__DIR__, 2) . '/templates/sponsoring/sponsors/detail.twig');

        $this->assertIsString($template);
        $this->assertStringContainsString('Kontakte zu dieser Vereinbarung', $template);
        $this->assertStringContainsString('data-contact-sponsorship=', $template);
        // Die Spalte zeigt Projekt und Paket statt der nackten Datensatz-Nummer.
        $this->assertStringNotContainsString("'#' ~ contact.sponsorship.id", $template);
    }
}
