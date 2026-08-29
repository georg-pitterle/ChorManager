<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Controllers\SponsorController;
use App\Controllers\SponsoringAttachmentController;
use App\Models\Attachment;
use App\Models\Sponsor;
use App\Models\Sponsorship;
use App\Policies\SponsoringPolicy;
use App\Util\SponsorshipStatus;
use PHPUnit\Framework\TestCase;
use Psr\Http\Message\ResponseInterface;
use Slim\Views\Twig;
use Tests\Unit\Bootstrap;

/**
 * Die zentrale Anhangsammlung führt Verträge (an der Vereinbarung) und Logos
 * (am Sponsor) zusammen, ohne eine dritte Ablage einzuführen.
 */
class SponsoringAttachmentOverviewFeatureTest extends TestCase
{
    use TestHttpHelpers;

    protected function setUp(): void
    {
        parent::setUp();
        $_SESSION = [];
        // Die Übersicht zeigt nur, was die anfragende Person auch einzeln
        // herunterladen dürfte - diese Prüfungen brauchen daher das Vollrecht.
        $_SESSION['can_manage_sponsoring'] = true;
        Bootstrap::setupTestDatabase();
    }

    protected function tearDown(): void
    {
        $_SESSION = [];
        parent::tearDown();
    }

    public function testOverviewListsBothSponsorAndAgreementAttachments(): void
    {
        $sponsor = Sponsor::create(['name' => 'Anhangtest ' . bin2hex(random_bytes(4))]);
        $sponsorship = Sponsorship::create([
            'sponsor_id' => $sponsor->id,
            'amount' => '100.00',
            'status' => SponsorshipStatus::ACCEPTED,
        ]);

        $logoName = 'logo-' . bin2hex(random_bytes(4)) . '.txt';
        $contractName = 'vertrag-' . bin2hex(random_bytes(4)) . '.txt';

        $logo = $this->makeAttachment('sponsor', (int) $sponsor->id, $logoName);
        $contract = $this->makeAttachment('sponsorship', (int) $sponsorship->id, $contractName);

        try {
            $captured = [];
            $twig = $this->createStub(Twig::class);
            $twig->method('render')->willReturnCallback(
                function ($response, string $template, array $data) use (&$captured): ResponseInterface {
                    $captured = $data;
                    return $response;
                }
            );

            (new SponsoringAttachmentController($twig, new SponsoringPolicy()))
                ->index($this->makeRequest('GET', '/sponsoring/attachments'), $this->makeResponse());

            $names = array_column($captured['attachments'], 'name');
            $this->assertContains($logoName, $names);
            $this->assertContains($contractName, $names);

            $rows = [];
            foreach ($captured['attachments'] as $row) {
                $rows[$row['name']] = $row;
            }

            $this->assertSame('Sponsor', $rows[$logoName]['context_label']);
            $this->assertSame(
                '/sponsoring/sponsors/' . $sponsor->id . '/attachments/' . $logo->id,
                $rows[$logoName]['download_url']
            );
            $this->assertSame('Vereinbarung', $rows[$contractName]['context_label']);
            $this->assertSame(
                '/sponsoring/sponsorships/' . $sponsorship->id . '/attachments/' . $contract->id,
                $rows[$contractName]['download_url']
            );
        } finally {
            Attachment::whereIn('id', [$logo->id, $contract->id])->delete();
            $sponsorship->delete();
            $sponsor->delete();
        }
    }

    public function testSponsorAttachmentDownloadRefusesAnAttachmentOfAnotherSponsor(): void
    {
        $sponsor = Sponsor::create(['name' => 'IDOR-Test A ' . bin2hex(random_bytes(4))]);
        $otherSponsor = Sponsor::create(['name' => 'IDOR-Test B ' . bin2hex(random_bytes(4))]);
        $attachment = $this->makeAttachment('sponsor', (int) $sponsor->id, 'geheim.txt');

        try {
            $response = $this->sponsorController()->downloadAttachment(
                $this->makeRequest('GET', '/sponsoring/sponsors/' . $otherSponsor->id . '/attachments/' . $attachment->id),
                $this->makeResponse(),
                ['id' => (string) $otherSponsor->id, 'attachment_id' => (string) $attachment->id]
            );

            $this->assertSame(403, $response->getStatusCode());
        } finally {
            $attachment->delete();
            $otherSponsor->delete();
            $sponsor->delete();
        }
    }

    private function makeAttachment(string $entityType, int $entityId, string $name): Attachment
    {
        $content = 'Testinhalt ' . $name;

        return Attachment::create([
            'entity_type'   => $entityType,
            'entity_id'     => $entityId,
            'filename'      => bin2hex(random_bytes(8)) . '_' . $name,
            'original_name' => $name,
            'mime_type'     => 'text/plain',
            'file_size'     => strlen($content),
            'file_content'  => $content,
        ]);
    }

    private function sponsorController(): SponsorController
    {
        return new SponsorController(
            $this->createStub(Twig::class),
            new SponsoringPolicy(),
            $this->attachmentService()
        );
    }
}
