<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Controllers\SponsorController;
use App\Models\Attachment;
use App\Models\Sponsor;
use App\Models\Sponsorship;
use App\Models\User;
use App\Policies\NewsletterPolicy;
use App\Policies\SponsoringPolicy;
use App\Policies\TaskPolicy;
use App\Services\AttachmentAccessRegistry;
use App\Util\PasswordHasher;
use App\Util\SponsorshipStatus;
use Illuminate\Database\Capsule\Manager as Capsule;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Psr\Http\Message\ResponseInterface;
use Slim\Psr7\Factory\StreamFactory;
use Slim\Psr7\UploadedFile;
use Slim\Views\Twig;
use Tests\Unit\Bootstrap;

/**
 * Logo-Dateien am Sponsor.
 *
 * Die Logos hängen am Sponsor, nicht an der Vereinbarung: Sponsert er wieder,
 * sind sie schon da. In der Praxis kommen sie in vielen Formaten und Varianten
 * (SVG, EPS, PNG hell, ein ZIP vom Grafikbüro) - und wer sie hochlädt, weiß
 * oft nicht, welche davon der Grafiker braucht. Deshalb mehrere Dateien je
 * Sponsor, in Grafikformaten bis 50 MB, getrennt von Mediadaten und Verträgen.
 *
 * Hochladen und Löschen darf neben dem Sponsoring-Team die zuständige Person
 * einer zugesagten Vereinbarung - sie besorgt die Logos. Dieses Recht gilt nur
 * für die Logo-Dateien, nicht für die übrigen Anhänge des Sponsors.
 */
final class SponsorLogoFeatureTest extends TestCase
{
    use TestHttpHelpers;
    use TwigViewStubs;

    private User $owner;
    private User $assignee;
    private Sponsor $sponsor;

    protected function setUp(): void
    {
        parent::setUp();
        Bootstrap::setupTestDatabase();
        Capsule::connection()->beginTransaction();

        $this->owner = $this->createUser();
        $this->assignee = $this->createUser();
        $this->sponsor = Sponsor::create([
            'name' => 'Logotest ' . bin2hex(random_bytes(4)),
            'created_by_user_id' => $this->owner->id,
        ]);
        $this->loginAs($this->owner);
    }

    protected function tearDown(): void
    {
        $connection = Capsule::connection();
        if ($connection->transactionLevel() > 0) {
            $connection->rollBack();
        }

        $_SESSION = [];
        parent::tearDown();
    }

    public function testTheEditorUploadsSeveralLogoFiles(): void
    {
        $response = $this->uploadLogos([$this->file('logo.svg', 'image/svg+xml'), $this->image('logo-hell.png')]);

        $this->assertRedirect($response, '/sponsoring/sponsors/' . $this->sponsor->id);
        $this->assertSame(['logo-hell.png', 'logo.svg'], $this->logoNames());
        $this->assertSame(0, $this->ordinaryAttachmentCount());
    }

    /**
     * @return array<string, array{string, string}>
     */
    public static function graphicFormats(): array
    {
        return [
            'ZIP vom Grafikbüro' => ['logos.zip', 'application/zip'],
            'EPS' => ['logo.eps', 'application/postscript'],
            'TIFF' => ['logo.tif', 'image/tiff'],
            'PDF' => ['logo.pdf', 'application/pdf'],
        ];
    }

    #[DataProvider('graphicFormats')]
    public function testGraphicFormatsAreAccepted(string $name, string $mimeType): void
    {
        $this->uploadLogos([$this->file($name, $mimeType)]);

        $this->assertSame([$name], $this->logoNames());
    }

    public function testAProgramIsRejected(): void
    {
        $this->uploadLogos([$this->file('setup.exe', 'application/x-msdownload')]);

        $this->assertStringContainsString('kein erlaubtes Logo-Format', (string) ($_SESSION['error'] ?? ''));
        $this->assertSame([], $this->logoNames());
    }

    public function testAnImageAboveTheAttachmentLimitIsStillAcceptedAsLogo(): void
    {
        // Bild-Anhänge enden sonst bei 2 MB; Druckvorlagen sind größer.
        $this->uploadLogos([$this->file('druck.png', 'image/png', str_repeat('x', 3 * 1024 * 1024))]);

        $this->assertSame(['druck.png'], $this->logoNames());
    }

    public function testTheDropzoneGetsAJsonAnswer(): void
    {
        $response = $this->controller()->uploadLogo(
            $this->makeRequest('POST', '/sponsoring/sponsors/' . $this->sponsor->id . '/logos', [], [], ['Accept' => 'application/json'])
                ->withUploadedFiles(['logos' => [$this->image('logo.png')]]),
            $this->makeResponse(),
            ['id' => (string) $this->sponsor->id]
        );

        $this->assertSame(200, $response->getStatusCode());
        $this->assertSame(true, json_decode((string) $response->getBody(), true)['ok'] ?? null);
    }

    public function testTheAssigneeOfAnAcceptedAgreementMayUploadLogos(): void
    {
        $this->agreement(SponsorshipStatus::ACCEPTED);
        $this->loginAs($this->assignee);

        $this->uploadLogos([$this->image('logo.png')]);

        $this->assertSame(['logo.png'], $this->logoNames());
    }

    /**
     * @return array<string, array{string}>
     */
    public static function inactiveStatuses(): array
    {
        return [
            'angefragt' => [SponsorshipStatus::REQUESTED],
            'abgelehnt' => [SponsorshipStatus::DECLINED],
            'abgeschlossen' => [SponsorshipStatus::CLOSED],
        ];
    }

    #[DataProvider('inactiveStatuses')]
    public function testTheAssigneeOfAnInactiveAgreementMayNot(string $status): void
    {
        $this->agreement($status);
        $this->loginAs($this->assignee);

        $response = $this->uploadLogos([$this->image('logo.png')]);

        $this->assertSame(403, $response->getStatusCode());
        $this->assertSame([], $this->logoNames());
    }

    public function testTheAssigneeMayNotUploadOrdinaryAttachments(): void
    {
        $this->agreement(SponsorshipStatus::ACCEPTED);
        $this->loginAs($this->assignee);

        $response = $this->controller()->uploadAttachment(
            $this->makeRequest('POST', '/sponsoring/sponsors/' . $this->sponsor->id . '/attachments')
                ->withUploadedFiles(['attachments' => [$this->image('anhang.png')]]),
            $this->makeResponse(),
            ['id' => (string) $this->sponsor->id]
        );

        $this->assertSame(403, $response->getStatusCode());
    }

    public function testTheAssigneeSeesTheLogosButNotOtherAttachments(): void
    {
        $this->uploadLogos([$this->image('logo.png')]);
        $logo = $this->logos()[0];
        $other = $this->ordinaryAttachment('vertrag.pdf');
        $this->agreement(SponsorshipStatus::ACCEPTED);
        $this->loginAs($this->assignee);

        $registry = $this->registry();

        $this->assertTrue($registry->mayAccess($logo));
        $this->assertFalse($registry->mayAccess($other));
    }

    public function testAnAssigneeWithoutSponsoringAccessNoLongerSeesTheLogos(): void
    {
        $this->uploadLogos([$this->image('logo.png')]);
        $logo = $this->logos()[0];
        $this->agreement(SponsorshipStatus::ACCEPTED);
        // Das Sponsoring-Recht wurde entzogen, die Zuständigkeit steht noch.
        $_SESSION = [
            'user_id' => (int) $this->assignee->id,
            'can_manage_sponsoring' => false,
            'can_create_own_sponsorships' => false,
        ];

        $this->assertFalse($this->registry()->mayAccess($logo));
        $this->assertFalse((new SponsoringPolicy($_SESSION))->canManageLogo($this->sponsor->fresh()));
    }

    public function testTheAssigneeMayDeleteAWrongLogoFile(): void
    {
        $this->uploadLogos([$this->image('falsch.png')]);
        $logo = $this->logos()[0];
        $this->agreement(SponsorshipStatus::ACCEPTED);
        $this->loginAs($this->assignee);

        $this->deleteLogo((int) $logo->id);

        $this->assertSame([], $this->logoNames());
    }

    public function testAnUninvolvedContributorMayNotDeleteALogoFile(): void
    {
        $this->uploadLogos([$this->image('logo.png')]);
        $logo = $this->logos()[0];
        $this->loginAs($this->assignee);

        $response = $this->deleteLogo((int) $logo->id);

        $this->assertSame(403, $response->getStatusCode());
        $this->assertSame(['logo.png'], $this->logoNames());
    }

    public function testDeletingALogoOfAnotherSponsorThroughThisSponsorFails(): void
    {
        $other = Sponsor::create(['name' => 'Fremder Logosponsor ' . bin2hex(random_bytes(4))]);
        $foreign = Attachment::create([
            'entity_type' => Sponsor::LOGO_ENTITY_TYPE,
            'entity_id' => $other->id,
            'filename' => 'x_fremd.png',
            'original_name' => 'fremd.png',
            'mime_type' => 'image/png',
            'file_size' => 4,
            'file_content' => 'test',
        ]);

        $this->deleteLogo((int) $foreign->id);

        $this->assertNotNull(Attachment::find($foreign->id));
    }

    public function testDeletingTheSponsorRemovesItsLogoFiles(): void
    {
        $this->uploadLogos([$this->image('logo.png')]);
        $logoId = (int) $this->logos()[0]->id;
        $_SESSION = ['user_id' => (int) $this->owner->id, 'can_manage_sponsoring' => true, 'can_create_own_sponsorships' => false];

        $this->controller()->delete(
            $this->makeRequest('POST', '/sponsoring/sponsors/' . $this->sponsor->id . '/delete'),
            $this->makeResponse(),
            ['id' => (string) $this->sponsor->id]
        );

        $this->assertNull(Sponsor::find($this->sponsor->id));
        $this->assertNull(Attachment::find($logoId));
    }

    public function testTheDetailPageOffersTheAssigneeTheLogoDropzoneButNotTheOtherFiles(): void
    {
        $this->ordinaryAttachment('mediadaten.pdf');
        $this->agreement(SponsorshipStatus::ACCEPTED);
        $this->loginAs($this->assignee);

        $html = $this->renderDetail();

        $this->assertStringContainsString('data-upload-url="/sponsoring/sponsors/' . $this->sponsor->id . '/logos"', $html);
        $this->assertStringContainsString('data-upload-field="logos[]"', $html);
        $this->assertStringContainsString('data-upload-max-bytes="52428800"', $html);
        $this->assertStringNotContainsString('data-upload-shrink-images', $this->logoSection($html));
        $this->assertStringNotContainsString('mediadaten.pdf', $html);
    }

    public function testTheLogosAreShownApartFromTheOtherAttachments(): void
    {
        $this->uploadLogos([$this->image('logo-druck.png'), $this->file('logo.svg', 'image/svg+xml')]);

        $html = $this->renderDetail();
        $section = $this->logoSection($html);

        $this->assertStringContainsString('logo-druck.png', $section);
        $this->assertStringContainsString('logo.svg', $section);
        // Vorschau nur für Bilder, die der Browser sicher anzeigt - SVG kann
        // Skripte enthalten und wird nur heruntergeladen.
        $this->assertSame(1, substr_count($section, 'class="sponsor-logo-preview"'));
        $this->assertStringContainsString('Noch keine Anhänge.', $html);
    }

    /**
     * @param list<UploadedFile> $files
     */
    private function uploadLogos(array $files): ResponseInterface
    {
        return $this->controller()->uploadLogo(
            $this->makeRequest('POST', '/sponsoring/sponsors/' . $this->sponsor->id . '/logos')
                ->withUploadedFiles(['logos' => $files]),
            $this->makeResponse(),
            ['id' => (string) $this->sponsor->id]
        );
    }

    private function deleteLogo(int $attachmentId): ResponseInterface
    {
        return $this->controller()->deleteLogo(
            $this->makeRequest('POST', '/sponsoring/sponsors/' . $this->sponsor->id . '/logos/' . $attachmentId . '/delete'),
            $this->makeResponse(),
            ['id' => (string) $this->sponsor->id, 'attachment_id' => (string) $attachmentId]
        );
    }

    /**
     * @return list<Attachment>
     */
    private function logos(): array
    {
        return Attachment::where('entity_type', Sponsor::LOGO_ENTITY_TYPE)
            ->where('entity_id', $this->sponsor->id)
            ->orderBy('original_name')
            ->get()
            ->all();
    }

    /**
     * @return list<string>
     */
    private function logoNames(): array
    {
        return array_map(static fn (Attachment $a): string => (string) $a->original_name, $this->logos());
    }

    private function ordinaryAttachmentCount(): int
    {
        return Attachment::where('entity_type', Sponsor::ENTITY_TYPE)->where('entity_id', $this->sponsor->id)->count();
    }

    private function ordinaryAttachment(string $name): Attachment
    {
        return Attachment::create([
            'entity_type' => Sponsor::ENTITY_TYPE,
            'entity_id' => $this->sponsor->id,
            'filename' => 'x_' . $name,
            'original_name' => $name,
            'mime_type' => 'application/pdf',
            'file_size' => 4,
            'file_content' => 'test',
        ]);
    }

    private function renderDetail(): string
    {
        $controller = new SponsorController(
            $this->createAppTwig('/sponsoring/sponsors'),
            new SponsoringPolicy($_SESSION),
            $this->attachmentService()
        );
        $response = $controller->detail(
            $this->makeRequest('GET', '/sponsoring/sponsors/' . $this->sponsor->id),
            $this->makeResponse(),
            ['id' => (string) $this->sponsor->id]
        );
        $this->assertSame(200, $response->getStatusCode());

        return (string) $response->getBody();
    }

    private function logoSection(string $html): string
    {
        $start = strpos($html, 'aria-labelledby="sponsor-logo-title"');
        $end = strpos($html, 'aria-labelledby="sponsor-attachments-title"');
        $this->assertNotFalse($start);
        $this->assertNotFalse($end);

        return substr($html, $start, $end - $start);
    }

    private function agreement(string $status): Sponsorship
    {
        return Sponsorship::create([
            'sponsor_id' => $this->sponsor->id,
            'amount' => '100.00',
            'status' => $status,
            'created_by_user_id' => $this->owner->id,
            'assigned_user_id' => $this->assignee->id,
        ]);
    }

    private function image(string $name): UploadedFile
    {
        return $this->file($name, 'image/png');
    }

    private function file(string $name, string $mediaType, string $content = 'Logoinhalt'): UploadedFile
    {
        return new UploadedFile(
            (new StreamFactory())->createStream($content),
            $name,
            $mediaType,
            strlen($content),
            UPLOAD_ERR_OK
        );
    }

    private function controller(): SponsorController
    {
        return new SponsorController($this->createStub(Twig::class), new SponsoringPolicy($_SESSION), $this->attachmentService());
    }

    private function registry(): AttachmentAccessRegistry
    {
        return new AttachmentAccessRegistry(
            new SponsoringPolicy($_SESSION),
            new TaskPolicy($_SESSION),
            ['finance' => true, 'sponsoring' => true, 'tasks' => true, 'newsletter' => true],
            new NewsletterPolicy($_SESSION),
            $_SESSION
        );
    }

    private function loginAs(User $user): void
    {
        $_SESSION = [
            'user_id' => (int) $user->id,
            'can_manage_sponsoring' => false,
            'can_create_own_sponsorships' => true,
        ];
    }

    private function createUser(): User
    {
        return User::create([
            'first_name' => 'Logo',
            'last_name' => 'Testperson',
            'email' => 'logo-' . bin2hex(random_bytes(6)) . '@example.test',
            'password' => PasswordHasher::hash('x'),
            'is_active' => 1,
        ]);
    }
}
