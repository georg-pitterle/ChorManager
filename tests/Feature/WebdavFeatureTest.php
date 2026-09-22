<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Controllers\WebdavController;
use App\Models\Attachment;
use App\Models\Project;
use App\Models\Song;
use App\Models\User;
use App\Services\AttachmentResponseFactory;
use App\Services\WebdavAccessService;
use App\Services\WebdavTreeService;
use App\Services\WebdavXmlBuilder;
use App\Util\PasswordHasher;
use Illuminate\Database\Capsule\Manager as Capsule;
use PHPUnit\Framework\TestCase;
use Psr\Http\Message\ResponseInterface;
use Psr\Log\NullLogger;
use Tests\Unit\Bootstrap;

/**
 * Der schreibgeschützte Noten-Ordner.
 *
 * Zwei Dinge stehen hier unter Beobachtung, weil sie sich still verschlechtern
 * können: dass ohne gültigen Token gar nichts herausgeht, und dass der Ordner
 * schreibgeschützt bleibt. Ein WebDAV-Klient, der eine Schreibmethode
 * unbeantwortet durchbekommt, hängt sonst den Mount beschreibbar ein.
 */
final class WebdavFeatureTest extends TestCase
{
    use TestHttpHelpers;

    private User $member;
    private User $stranger;
    private Project $project;
    private Project $foreignProject;
    private string $token;

    protected function setUp(): void
    {
        parent::setUp();
        Bootstrap::setupTestDatabase();
        Capsule::connection()->beginTransaction();

        $this->member = $this->createUser('mitglied');
        $this->stranger = $this->createUser('fremd');

        $this->project = $this->createProject('Herbstkonzert');
        $this->foreignProject = $this->createProject('Fremdprojekt');

        Capsule::table('project_users')->insert([
            'project_id' => (int) $this->project->id,
            'user_id' => (int) $this->member->id,
        ]);
        Capsule::table('project_users')->insert([
            'project_id' => (int) $this->foreignProject->id,
            'user_id' => (int) $this->stranger->id,
        ]);

        $service = new WebdavAccessService(new NullLogger());
        $this->token = $service->rotateTokenForUser((int) $this->member->id);
    }

    protected function tearDown(): void
    {
        $connection = Capsule::connection();
        if ($connection->transactionLevel() > 0) {
            $connection->rollBack();
        }

        parent::tearDown();
    }

    public function testWithoutCredentialsTheServerAsksForThem(): void
    {
        $response = $this->call('PROPFIND', '', null, ['Depth' => '1']);

        $this->assertSame(401, $response->getStatusCode());
        $this->assertStringContainsString('Basic', $response->getHeaderLine('WWW-Authenticate'));
    }

    public function testAWrongTokenIsRejected(): void
    {
        $response = $this->call('PROPFIND', '', str_repeat('0', 64), ['Depth' => '0']);

        $this->assertSame(401, $response->getStatusCode());
    }

    public function testAnArchivedMemberLosesAccess(): void
    {
        $this->member->is_active = 0;
        $this->member->save();

        $this->assertSame(401, $this->call('PROPFIND', '', $this->token, ['Depth' => '0'])->getStatusCode());
    }

    public function testTheRootListsOnlyTheOwnProjects(): void
    {
        $response = $this->call('PROPFIND', '', $this->token, ['Depth' => '1']);
        $body = (string) $response->getBody();

        $this->assertSame(207, $response->getStatusCode());
        $this->assertStringContainsString('Herbstkonzert', $body);
        $this->assertStringNotContainsString('Fremdprojekt', $body);
    }

    public function testAForeignProjectIsNotFound(): void
    {
        $response = $this->call('PROPFIND', 'Fremdprojekt', $this->token, ['Depth' => '1']);

        $this->assertSame(404, $response->getStatusCode());
    }

    public function testAnInfiniteDepthIsRefusedWithTheProtocolsOwnAnswer(): void
    {
        $response = $this->call('PROPFIND', '', $this->token, ['Depth' => 'infinity']);

        $this->assertSame(403, $response->getStatusCode());
        $this->assertStringContainsString('propfind-finite-depth', (string) $response->getBody());
    }

    public function testOwnSheetMusicIsDelivered(): void
    {
        $this->assignSong($this->project, 'Ave Maria', 'ave.pdf', 'Noteninhalt');

        $response = $this->call('GET', 'Herbstkonzert/Ave Maria/ave.pdf', $this->token);

        $this->assertSame(200, $response->getStatusCode());
        $this->assertSame('Noteninhalt', (string) $response->getBody());
        $this->assertSame('application/pdf', $response->getHeaderLine('Content-Type'));
    }

    public function testARangeRequestAnswersWithAPartialResponse(): void
    {
        $this->assignSong($this->project, 'Ave Maria', 'ave.pdf', 'Noteninhalt');

        $response = $this->call(
            'GET',
            'Herbstkonzert/Ave Maria/ave.pdf',
            $this->token,
            ['Range' => 'bytes=0-3']
        );

        $this->assertSame(206, $response->getStatusCode());
        $this->assertSame('Note', (string) $response->getBody());
    }

    public function testSheetMusicOfAForeignProjectIsNotFound(): void
    {
        $this->assignSong($this->foreignProject, 'Geheim', 'geheim.pdf', 'Fremde Noten');

        $response = $this->call('GET', 'Fremdprojekt/Geheim/geheim.pdf', $this->token);

        $this->assertSame(404, $response->getStatusCode());
    }

    public function testTheOptionsAnswerDeclaresOnlyReadingMethods(): void
    {
        $response = $this->call('OPTIONS', '', $this->token);

        $this->assertSame(200, $response->getStatusCode());
        $this->assertStringContainsString('1', $response->getHeaderLine('DAV'));
        $this->assertStringNotContainsString('PUT', $response->getHeaderLine('Allow'));
    }

    /**
     * @return list<array{0: string}>
     */
    public static function writingMethodProvider(): array
    {
        return [['PUT'], ['DELETE'], ['MKCOL'], ['MOVE'], ['COPY'], ['PROPPATCH'], ['LOCK']];
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('writingMethodProvider')]
    public function testEveryWritingMethodIsRefused(string $method): void
    {
        $response = $this->call($method, 'Herbstkonzert', $this->token);

        $this->assertSame(403, $response->getStatusCode());
    }

    /**
     * Die Adresse, die dauerhaft in einer Noten-App steht, folgt der fest
     * konfigurierten Basisadresse - nicht dem Host-Kopf der Anfrage, der frei
     * wählbar ist. Selbst zusammengesetzt stand dort zwischenzeitlich
     * `https://chormanager.ddev.site:80/webdav/`: Schema vom Proxy, Port vom
     * internen Server.
     */
    public function testTheAdvertisedAddressFollowsTheConfiguredAppUrl(): void
    {
        $previous = $_ENV['APP_URL'] ?? null;
        $_ENV['APP_URL'] = 'https://chor.example.org';

        try {
            $request = $this->makeRequest('GET', 'http://internal.local:8080/downloads')
                ->withHeader('Host', 'angreifer.example.net');

            $this->assertSame('https://chor.example.org/webdav/', WebdavController::baseUrl($request));
        } finally {
            if ($previous === null) {
                unset($_ENV['APP_URL']);
            } else {
                $_ENV['APP_URL'] = $previous;
            }
        }
    }

    public function testAGetOnACollectionIsNotAllowed(): void
    {
        $response = $this->call('GET', 'Herbstkonzert', $this->token);

        $this->assertSame(405, $response->getStatusCode());
    }

    private function call(string $method, string $path, ?string $token = null, array $headers = []): ResponseInterface
    {
        $segments = $path === '' ? [] : array_map('rawurlencode', explode('/', $path));
        $uri = '/webdav' . ($segments === [] ? '/' : '/' . implode('/', $segments));

        if ($token !== null) {
            $headers['Authorization'] = 'Basic ' . base64_encode($this->member->email . ':' . $token);
        }

        $request = $this->makeRequest($method, $uri, [], [], $headers);
        $request->getBody()->write(
            '<?xml version="1.0" encoding="utf-8"?><D:propfind xmlns:D="DAV:"><D:allprop/></D:propfind>'
        );
        $request->getBody()->rewind();

        return $this->makeController()->handle($request, $this->makeResponse());
    }

    private function makeController(): WebdavController
    {
        return new WebdavController(
            new WebdavAccessService(new NullLogger()),
            new WebdavTreeService(),
            new WebdavXmlBuilder(),
            new AttachmentResponseFactory()
        );
    }

    private function createProject(string $name): Project
    {
        return Project::create([
            'name' => $name,
            'description' => 'Testprojekt',
            'start_date' => '2026-09-01',
            'end_date' => '2026-12-31',
        ]);
    }

    private function assignSong(Project $project, string $title, string $fileName, string $content): void
    {
        $song = Song::create([
            'title' => $title,
            'composer' => 'Testkomponist',
            'created_by_user_id' => (int) $this->member->id,
        ]);

        Capsule::table('project_song_assignments')->insert([
            'project_id' => (int) $project->id,
            'song_id' => (int) $song->id,
            'note' => null,
            'created_at' => date('Y-m-d H:i:s'),
        ]);

        Attachment::create([
            'entity_type' => 'song',
            'entity_id' => (int) $song->id,
            'filename' => bin2hex(random_bytes(8)) . '_' . $fileName,
            'original_name' => $fileName,
            'mime_type' => 'application/pdf',
            'file_size' => strlen($content),
            'file_content' => $content,
        ]);
    }

    private function createUser(string $prefix): User
    {
        return User::create([
            'email' => $prefix . '-' . bin2hex(random_bytes(6)) . '@example.test',
            'password' => PasswordHasher::hash('irrelevant'),
            'first_name' => 'Test',
            'last_name' => 'Person',
            'is_active' => 1,
        ]);
    }
}
