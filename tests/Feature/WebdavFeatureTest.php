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

    private const ALLPROP_BODY = '<?xml version="1.0" encoding="utf-8"?><D:propfind xmlns:D="DAV:"><D:allprop/></D:propfind>';

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

    /**
     * MobileSheets bietet WebDAV nur als "Nextcloud-Server" an und hängt an die
     * eingegebene Adresse `remote.php/dav/files/<benutzer>/` an. Belegt durch
     * die Protokollzeile vom 24.09.2026, 15:07 UTC:
     *
     *   PROPFIND /webdav/remote.php/dav/files/<mail>/ 404 MobileSheets/2 CFNetwork/...
     *
     * Die 404 war die ganze Fehlermeldung, die der Nutzer zu sehen bekam.
     */
    public function testTheNextcloudPathLeadsToTheSameFolder(): void
    {
        $response = $this->call(
            'PROPFIND',
            'remote.php/dav/files/' . $this->member->email,
            $this->token,
            ['Depth' => '1']
        );

        $this->assertSame(207, $response->getStatusCode());
        $this->assertStringContainsString('Herbstkonzert', (string) $response->getBody());
    }

    /**
     * Die href der Antwort trägt die Wegstrecke des Klienten weiter - sonst
     * findet er in der 207-Antwort seine eigene Anfrage nicht wieder.
     */
    public function testTheAnswerKeepsThePathTheClientAsked(): void
    {
        $response = $this->call(
            'PROPFIND',
            'remote.php/dav/files/' . $this->member->email,
            $this->token,
            ['Depth' => '1']
        );

        $body = (string) $response->getBody();

        $this->assertStringContainsString('/webdav/remote.php/dav/files/', $body);
        $this->assertStringNotContainsString('<D:href>/webdav/Herbstkonzert/</D:href>', $body);
    }

    public function testSheetMusicIsAlsoReachableThroughTheNextcloudPath(): void
    {
        $this->assignSong($this->project, 'Ave Maria', 'ave.pdf', 'Noteninhalt');

        $response = $this->call(
            'GET',
            'remote.php/dav/files/' . $this->member->email . '/Herbstkonzert/Ave Maria/ave.pdf',
            $this->token
        );

        $this->assertSame(200, $response->getStatusCode());
        $this->assertSame('Noteninhalt', (string) $response->getBody());
    }

    /**
     * Die ältere ownCloud-Form derselben Wegstrecke.
     */
    public function testTheOwncloudPathLeadsToTheSameFolder(): void
    {
        $response = $this->call('PROPFIND', 'remote.php/webdav', $this->token, ['Depth' => '1']);

        $this->assertSame(207, $response->getStatusCode());
        $this->assertStringContainsString('Herbstkonzert', (string) $response->getBody());
    }

    /**
     * Der Benutzer im Pfad entscheidet nichts - das tut allein das Token. Ein
     * fremder Name darf deshalb weder mehr noch weniger zeigen.
     */
    public function testTheUserInThePathGrantsNothing(): void
    {
        $response = $this->call(
            'PROPFIND',
            'remote.php/dav/files/' . $this->stranger->email,
            $this->token,
            ['Depth' => '1']
        );

        $body = (string) $response->getBody();

        $this->assertSame(207, $response->getStatusCode());
        $this->assertStringContainsString('Herbstkonzert', $body);
        $this->assertStringNotContainsString('Fremdprojekt', $body);
    }

    public function testAGetOnACollectionIsNotAllowed(): void
    {
        $response = $this->call('GET', 'Herbstkonzert', $this->token);

        $this->assertSame(405, $response->getStatusCode());
    }

    /**
     * MobileSheets meldete bei der Einrichtung "Text '' could not be parsed at
     * index 0". Ein Ordner ohne Datum stand als leeres `getlastmodified` im
     * 404-Abschnitt - vermutlich liest die App dieses Element, ohne auf den
     * Status des Abschnitts zu schauen. Ein Datum an jedem Eintrag nimmt ihr
     * den leeren Wert.
     *
     * @return array<string, array{0: string}>
     */
    public static function propfindBodyProvider(): array
    {
        return [
            'allprop' => [self::ALLPROP_BODY],
            'angefragte Eigenschaft' => [
                '<?xml version="1.0" encoding="utf-8"?><D:propfind xmlns:D="DAV:">'
                    . '<D:prop><D:getlastmodified/></D:prop></D:propfind>',
            ],
        ];
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('propfindBodyProvider')]
    public function testEveryEntryCarriesAReadableDate(string $body): void
    {
        $this->assignSong($this->project, 'Ave Maria', 'ave.pdf', 'Noteninhalt');
        $this->attachToProject($this->createProject('Leeres Projekt'));

        foreach (['', 'Herbstkonzert', 'Herbstkonzert/Ave Maria', 'Leeres Projekt'] as $path) {
            $this->assertEveryEntryHasADate(
                $this->call('PROPFIND', $path, $this->token, ['Depth' => '1'], $body)
            );
        }
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('propfindBodyProvider')]
    public function testARootWithoutProjectsStillCarriesADate(string $body): void
    {
        Capsule::table('project_users')->where('user_id', (int) $this->member->id)->delete();

        $this->assertEveryEntryHasADate(
            $this->call('PROPFIND', '', $this->token, ['Depth' => '0'], $body)
        );
    }

    /**
     * Ein Ordner trägt das Datum seines jüngsten Inhalts - nur so erkennt ein
     * Klient an der Wurzel, dass sich weiter unten etwas geändert hat.
     */
    public function testAFolderCarriesTheDateOfItsNewestContent(): void
    {
        $this->assignSong($this->project, 'Ave Maria', 'ave.pdf', 'Noteninhalt');
        Capsule::table('songs')->update(['created_at' => '2026-01-10 12:00:00']);
        Capsule::table('attachments')->update(['created_at' => '2026-03-15 12:00:00']);

        $response = $this->call('PROPFIND', '', $this->token, ['Depth' => '1']);

        $this->assertSame(
            ['Sun, 15 Mar 2026', 'Sun, 15 Mar 2026'],
            array_map(
                static fn(string $date): string => substr($date, 0, 16),
                $this->lastModifiedValues($response)
            )
        );
    }

    /**
     * Dieselbe Regel eine Ebene tiefer: Der Liedordner ist der Ordner, in dem
     * die Noten wirklich liegen. Trüge er nur das Anlagedatum des Liedes, bliebe
     * sein Datum beim Nachreichen eines Notenblattes stehen, während Projekt und
     * Wurzel darüber sich änderten - ein Klient, der am Ordnerdatum entscheidet,
     * liest genau den Ordner nicht neu, in dem die neue Datei steht.
     */
    public function testASongFolderCarriesTheDateOfItsNewestAttachment(): void
    {
        $this->assignSong($this->project, 'Ave Maria', 'ave.pdf', 'Noteninhalt');
        Capsule::table('songs')->update(['created_at' => '2026-01-10 12:00:00']);
        Capsule::table('attachments')->update(['created_at' => '2026-03-15 12:00:00']);

        $response = $this->call('PROPFIND', 'Herbstkonzert', $this->token, ['Depth' => '1']);

        $this->assertSame(
            ['Sun, 15 Mar 2026', 'Sun, 15 Mar 2026'],
            array_map(
                static fn(string $date): string => substr($date, 0, 16),
                $this->lastModifiedValues($response)
            )
        );
    }

    private function assertEveryEntryHasADate(ResponseInterface $response): void
    {
        $this->assertSame(207, $response->getStatusCode());

        $document = new \DOMDocument();
        $document->loadXML((string) $response->getBody());

        $entries = $document->getElementsByTagNameNS('DAV:', 'response')->length;
        $values = $this->lastModifiedValues($response);

        $this->assertGreaterThan(0, $entries);
        $this->assertCount($entries, $values);

        foreach ($values as $value) {
            $this->assertNotFalse(
                \DateTimeImmutable::createFromFormat('D, d M Y H:i:s \G\M\T', $value),
                'Kein gültiges HTTP-Datum: "' . $value . '"'
            );
        }
    }

    /**
     * @return list<string>
     */
    private function lastModifiedValues(ResponseInterface $response): array
    {
        $document = new \DOMDocument();
        $document->loadXML((string) $response->getBody());

        $values = [];
        foreach ($document->getElementsByTagNameNS('DAV:', 'getlastmodified') as $element) {
            $values[] = (string) $element->textContent;
        }

        return $values;
    }

    private function attachToProject(Project $project): void
    {
        Capsule::table('project_users')->insert([
            'project_id' => (int) $project->id,
            'user_id' => (int) $this->member->id,
        ]);
    }

    private function call(
        string $method,
        string $path,
        ?string $token = null,
        array $headers = [],
        string $body = self::ALLPROP_BODY
    ): ResponseInterface {
        $segments = $path === '' ? [] : array_map('rawurlencode', explode('/', $path));
        $uri = '/webdav' . ($segments === [] ? '/' : '/' . implode('/', $segments));

        if ($token !== null) {
            $headers['Authorization'] = 'Basic ' . base64_encode($this->member->email . ':' . $token);
        }

        $request = $this->makeRequest($method, $uri, [], [], $headers);
        $request->getBody()->write($body);
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
