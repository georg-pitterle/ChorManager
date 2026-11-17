<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Controllers\SponsorshipController;
use App\Models\Attachment;
use App\Models\Project;
use App\Models\Sponsor;
use App\Models\Sponsorship;
use App\Models\User;
use App\Util\PasswordHasher;
use App\Util\SponsorshipStatus;
use Illuminate\Database\Capsule\Manager as Capsule;
use PHPUnit\Framework\TestCase;
use Psr\Http\Message\ResponseInterface;
use Slim\Psr7\Factory\StreamFactory;
use Slim\Psr7\UploadedFile;
use Tests\Unit\Bootstrap;

/**
 * Verträge und Belege haben ein eigenes Ablagefeld an der Anfrage, bekommen
 * dort einen sprechenden Namen und lassen sich umbenennen - von dem, der die
 * Anfrage ändern darf.
 */
final class SponsorshipContractUploadFeatureTest extends TestCase
{
    use TestHttpHelpers;
    use BuildsControllers;

    private Sponsor $sponsor;
    private Sponsorship $request;
    private User $author;

    protected function setUp(): void
    {
        parent::setUp();
        Bootstrap::setupTestDatabase();
        Capsule::connection()->beginTransaction();

        $this->author = $this->person();
        $this->sponsor = Sponsor::create(['name' => 'Vertragstest AG']);
        $project = Project::create(['name' => 'Herbstkonzert']);
        $this->request = Sponsorship::create([
            'sponsor_id' => $this->sponsor->id,
            'project_id' => $project->id,
            'amount' => '100.00',
            'status' => SponsorshipStatus::ACCEPTED,
            'created_by_user_id' => $this->author->id,
        ]);
        $_SESSION = ['user_id' => (int) $this->author->id, 'can_create_own_sponsorships' => true];
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

    public function testAnUploadedContractIsNamedAfterSponsorAndProject(): void
    {
        $response = $this->upload([$this->pdf('SCAN0126.pdf'), $this->pdf('SCAN0127.pdf')]);

        $this->assertSame(302, $response->getStatusCode());
        $this->assertSame(
            ['Vertragstest-AG_Herbstkonzert.pdf', 'Vertragstest-AG_Herbstkonzert_2.pdf'],
            $this->names()
        );
    }

    public function testSomeoneElseMayNotUploadToTheRequest(): void
    {
        $_SESSION = ['user_id' => (int) $this->person()->id, 'can_create_own_sponsorships' => true];

        $response = $this->upload([$this->pdf('SCAN0126.pdf')]);

        $this->assertSame(403, $response->getStatusCode());
        $this->assertSame([], $this->names());
    }

    public function testTheAuthorRenamesAFileAndTheExtensionStays(): void
    {
        $this->upload([$this->pdf('SCAN0126.pdf')]);
        $attachment = $this->attachments()->firstOrFail();

        $response = $this->rename((int) $attachment->id, 'Vertrag unterschrieben');

        $this->assertRedirect($response, '/sponsoring/sponsors/' . $this->sponsor->id);
        $this->assertSame(['Vertrag unterschrieben.pdf'], $this->names());
    }

    public function testSomeoneElseMayNotRename(): void
    {
        $this->upload([$this->pdf('SCAN0126.pdf')]);
        $attachment = $this->attachments()->firstOrFail();
        $_SESSION = ['user_id' => (int) $this->person()->id, 'can_create_own_sponsorships' => true];

        $response = $this->rename((int) $attachment->id, 'Gekapert');

        $this->assertSame(403, $response->getStatusCode());
        $this->assertSame(['Vertragstest-AG_Herbstkonzert.pdf'], $this->names());
    }

    public function testAFileOfAnotherRequestIsNotRenamed(): void
    {
        $other = Sponsorship::create([
            'sponsor_id' => $this->sponsor->id,
            'amount' => '0.00',
            'status' => SponsorshipStatus::REQUESTED,
            'created_by_user_id' => $this->author->id,
        ]);
        $this->upload([$this->pdf('SCAN0126.pdf')]);
        $attachment = $this->attachments()->firstOrFail();

        $this->controller()->renameAttachment(
            $this->makeRequest('POST', '/x', ['name' => 'Falsche Anfrage']),
            $this->makeResponse(),
            ['id' => (string) $other->id, 'attachment_id' => (string) $attachment->id]
        );

        $this->assertSame(['Vertragstest-AG_Herbstkonzert.pdf'], $this->names());
    }

    /**
     * @param list<UploadedFile> $files
     */
    private function upload(array $files): ResponseInterface
    {
        return $this->controller()->uploadAttachment(
            $this->makeRequest('POST', '/sponsoring/sponsorships/' . $this->request->id . '/attachments')
                ->withUploadedFiles(['attachments' => $files]),
            $this->makeResponse(),
            ['id' => (string) $this->request->id]
        );
    }

    private function rename(int $attachmentId, string $name): ResponseInterface
    {
        return $this->controller()->renameAttachment(
            $this->makeRequest('POST', '/x', ['name' => $name]),
            $this->makeResponse(),
            ['id' => (string) $this->request->id, 'attachment_id' => (string) $attachmentId]
        );
    }

    private function controller(): SponsorshipController
    {
        return $this->sponsorshipController(['attachments' => $this->attachmentService()]);
    }

    /**
     * @return \Illuminate\Database\Eloquent\Builder<Attachment>
     */
    private function attachments(): \Illuminate\Database\Eloquent\Builder
    {
        return Attachment::where('entity_type', Sponsorship::ENTITY_TYPE)
            ->where('entity_id', $this->request->id)
            ->orderBy('id');
    }

    /**
     * @return list<string>
     */
    private function names(): array
    {
        return $this->attachments()->pluck('original_name')->all();
    }

    private function pdf(string $name): UploadedFile
    {
        $content = "%PDF-1.4\n1 0 obj\n<<>>\nendobj\ntrailer\n<<>>\n%%EOF\n";

        return new UploadedFile(
            (new StreamFactory())->createStream($content),
            $name,
            'application/pdf',
            strlen($content),
            UPLOAD_ERR_OK
        );
    }

    private function person(): User
    {
        return User::create([
            'first_name' => 'Vertrag',
            'last_name' => 'Testperson',
            'email' => 'contract-' . bin2hex(random_bytes(6)) . '@example.test',
            'password' => PasswordHasher::hash('x'),
            'is_active' => 1,
        ]);
    }
}
