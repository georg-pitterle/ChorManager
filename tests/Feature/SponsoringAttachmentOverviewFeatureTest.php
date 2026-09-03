<?php

declare(strict_types=1);

namespace Tests\Feature;

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
            $this->assertArrayNotHasKey('download_url', $rows[$logoName]);
            $this->assertSame('Vereinbarung', $rows[$contractName]['context_label']);
            $this->assertArrayNotHasKey('download_url', $rows[$contractName]);
        } finally {
            Attachment::whereIn('id', [$logo->id, $contract->id])->delete();
            $sponsorship->delete();
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
}
