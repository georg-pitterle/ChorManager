<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Controllers\DownloadController;
use App\Models\Attachment;
use App\Models\Project;
use App\Models\Song;
use App\Models\User;
use App\Util\PasswordHasher;
use DI\Container;
use DI\ContainerBuilder;
use PHPUnit\Framework\TestCase;
use Slim\Views\Twig;
use Tests\Unit\Bootstrap;

/**
 * Aufbau der Probenmaterial-Seite: laufende Projekte zuerst und das erste
 * aufgeklappt, vergangene getrennt darunter, je Lied eine schlichte Dateiliste
 * statt einer Tabelle mit MIME-Typ, MIDI-Skripte nur bei MIDI-Dateien.
 *
 * Gerendert wird mit dem echten Container - eine Textsuche im Template bliebe
 * grün, auch wenn die Seite die Gruppen falsch bildet.
 */
final class DownloadsPageLayoutFeatureTest extends TestCase
{
    use TestHttpHelpers;

    private ?Container $container = null;

    /** @var list<object> */
    private array $cleanup = [];

    protected function setUp(): void
    {
        parent::setUp();
        Bootstrap::setupTestDatabase();

        // Die Twig-Factory startet die Session und leert dabei $_SESSION.
        $this->container()->get(Twig::class);
        $_SESSION = [];
    }

    protected function tearDown(): void
    {
        foreach (array_reverse($this->cleanup) as $model) {
            $model->delete();
        }
        $this->cleanup = [];
        $_SESSION = [];
        parent::tearDown();
    }

    private function container(): Container
    {
        if ($this->container === null) {
            $builder = new ContainerBuilder();
            $settings = require dirname(__DIR__, 2) . '/src/Settings.php';
            $settings($builder);
            $dependencies = require dirname(__DIR__, 2) . '/src/Dependencies.php';
            $dependencies($builder);
            $this->container = $builder->build();
        }

        return $this->container;
    }

    private function track(object $model): object
    {
        $this->cleanup[] = $model;

        return $model;
    }

    private function makeAttachment(int $songId, string $name, string $mime): Attachment
    {
        $content = 'Testinhalt ' . $name;

        /** @var Attachment $attachment */
        $attachment = $this->track(Attachment::create([
            'entity_type' => 'song',
            'entity_id' => $songId,
            'filename' => bin2hex(random_bytes(8)) . '_' . $name,
            'original_name' => $name,
            'mime_type' => $mime,
            'file_size' => strlen($content),
            'file_content' => $content,
        ]));

        return $attachment;
    }

    /**
     * @param array<string, string|null> $projectDates start_date/end_date je Projektname
     * @return array{user: User, projects: array<string, Project>, song: Song}
     */
    private function makeMemberWithProjects(array $projectDates): array
    {
        /** @var User $user */
        $user = $this->track(User::create([
            'first_name' => 'Proben',
            'last_name' => 'Material',
            'email' => 'proben.' . bin2hex(random_bytes(4)) . '@example.test',
            'password' => PasswordHasher::hash('test123'),
            'is_active' => 1,
        ]));
        /** @var Song $song */
        $song = $this->track(Song::create(['title' => 'Probenlied ' . bin2hex(random_bytes(4))]));

        $projects = [];
        foreach ($projectDates as $name => $dates) {
            /** @var Project $project */
            $project = $this->track(Project::create([
                'name' => $name,
                'start_date' => $dates['start'] ?? null,
                'end_date' => $dates['end'] ?? null,
            ]));
            $project->users()->attach($user->id);
            $project->assignedSongs()->attach($song->id, ['created_at' => date('Y-m-d H:i:s')]);
            $projects[$name] = $project;
        }

        return ['user' => $user, 'projects' => $projects, 'song' => $song];
    }

    private function renderIndexFor(User $user): string
    {
        $_SESSION['user_id'] = (int) $user->id;
        $controller = $this->container()->get(DownloadController::class);
        $response = $controller->index($this->makeRequest('GET', '/downloads'), $this->makeResponse());

        return (string) $response->getBody();
    }

    public function testSplitByEndPutsFinishedProjectsIntoPast(): void
    {
        $running = new Project(['name' => 'läuft', 'start_date' => '2026-09-01', 'end_date' => '2026-12-31']);
        $open = new Project(['name' => 'ohne Ende', 'start_date' => '2026-01-01', 'end_date' => null]);
        $endsToday = new Project(['name' => 'endet heute', 'start_date' => '2026-05-01', 'end_date' => '2026-10-10']);
        $finished = new Project(['name' => 'vorbei', 'start_date' => '2026-01-01', 'end_date' => '2026-06-30']);

        $split = DownloadController::splitByEnd([$running, $open, $endsToday, $finished], '2026-10-10');

        $this->assertSame(['läuft', 'ohne Ende', 'endet heute'], array_map(fn ($p) => $p->name, $split['current']));
        $this->assertSame(['vorbei'], array_map(fn ($p) => $p->name, $split['past']));
    }

    public function testCurrentProjectIsOpenAndPastProjectsAreGroupedBelow(): void
    {
        $name = bin2hex(random_bytes(3));
        $fixture = $this->makeMemberWithProjects([
            "Laufend {$name}" => ['start' => date('Y-m-d', strtotime('-10 days')), 'end' => date('Y-m-d', strtotime('+30 days'))],
            "Vorbei {$name}" => ['start' => '2020-01-01', 'end' => '2020-06-30'],
        ]);
        $this->makeAttachment((int) $fixture['song']->id, 'Noten.pdf', 'application/pdf');

        $html = $this->renderIndexFor($fixture['user']);

        // Genau ein Projekt ist aufgeklappt: das laufende.
        $this->assertSame(
            1,
            preg_match_all('/data-bs-target="#download-collapse-\d+"\s+aria-expanded="true"/', $html)
        );
        $this->assertSame(
            1,
            preg_match(
                '/aria-expanded="true"[^>]*>\s*<span[^>]*>\s*<span[^>]*>Laufend ' . $name . '</',
                $html
            )
        );

        // Vergangene stehen unter eigener Gruppe, nach den laufenden.
        $this->assertStringContainsString('Frühere Projekte', $html);
        $this->assertLessThan(
            strpos($html, 'Frühere Projekte'),
            strpos($html, "Laufend {$name}")
        );
        $this->assertGreaterThan(
            strpos($html, 'Frühere Projekte'),
            strpos($html, "Vorbei {$name}")
        );

        // Projektzeile nennt den Zeitraum.
        $this->assertStringContainsString('01.01.2020', $html);
        $this->assertStringContainsString('30.06.2020', $html);
    }

    public function testPastGroupIsAbsentWhenAllProjectsAreCurrent(): void
    {
        $fixture = $this->makeMemberWithProjects([
            'Nur laufend ' . bin2hex(random_bytes(3)) => ['start' => date('Y-m-d'), 'end' => null],
        ]);

        $html = $this->renderIndexFor($fixture['user']);

        $this->assertStringNotContainsString('Frühere Projekte', $html);
    }

    public function testFilesRenderAsPlainListWithoutMimeColumnOrViewToggle(): void
    {
        $fixture = $this->makeMemberWithProjects(['Liste ' . bin2hex(random_bytes(3)) => ['start' => date('Y-m-d')]]);
        $songId = (int) $fixture['song']->id;
        $this->makeAttachment($songId, 'Stimme.mp3', 'audio/mpeg');
        $this->makeAttachment($songId, 'Noten.pdf', 'application/pdf');

        $html = $this->renderIndexFor($fixture['user']);

        $this->assertStringContainsString('Noten.pdf', $html);
        $this->assertStringContainsString('Stimme.mp3', $html);

        // Weder MIME-Typ als Text noch Ansichtsumschalter noch Tabellen-Engine.
        $this->assertStringNotContainsString('>audio/mpeg<', $html);
        $this->assertStringNotContainsString('application/pdf</small>', $html);
        $this->assertStringNotContainsString('data-table-view-toggle', $html);
        $this->assertStringNotContainsString('data-table-engine', $html);
        $this->assertStringNotContainsString('downloadsSongTable', $html);

        // Die Aktionen tragen ihre Beschriftung auch am Telefon (kein d-none unter sm).
        $this->assertStringContainsString('>Download</span>', $html);
        $this->assertStringNotContainsString('d-none d-sm-inline">Download', $html);
        $this->assertStringNotContainsString('d-none d-sm-inline">Vorschau', $html);

        // Kein Dauer-Hinweis für nicht abspielbare Dateien.
        $this->assertStringNotContainsString('Nicht direkt abspielbar', $html);

        // Die Dateiart steht in Worten.
        $this->assertStringContainsString('Noten', $html);
        $this->assertStringContainsString('Übe-Audio', $html);
    }

    public function testPlayersCarryTheFileNameAsAccessibleName(): void
    {
        $fixture = $this->makeMemberWithProjects(['Player ' . bin2hex(random_bytes(3)) => ['start' => date('Y-m-d')]]);
        $songId = (int) $fixture['song']->id;
        $this->makeAttachment($songId, 'Sopran.mp3', 'audio/mpeg');
        $this->makeAttachment($songId, 'Begleitung.mid', 'audio/midi');

        $html = $this->renderIndexFor($fixture['user']);

        $this->assertMatchesRegularExpression('/<audio[^>]*aria-label="[^"]*Sopran\.mp3[^"]*"/', $html);
        $this->assertMatchesRegularExpression('/<midi-player[^>]*aria-label="[^"]*Begleitung\.mid[^"]*"/', $html);
    }

    public function testMidiScriptsLoadOnlyWhenAMidiFileIsOnThePage(): void
    {
        $fixture = $this->makeMemberWithProjects(['Skripte ' . bin2hex(random_bytes(3)) => ['start' => date('Y-m-d')]]);
        $songId = (int) $fixture['song']->id;
        $this->makeAttachment($songId, 'Stimme.mp3', 'audio/mpeg');

        $withoutMidi = $this->renderIndexFor($fixture['user']);

        $this->assertStringNotContainsString('/vendor/tone/Tone.js', $withoutMidi);
        $this->assertStringNotContainsString('/vendor/magenta-music/core.js', $withoutMidi);
        $this->assertStringNotContainsString('midi-player.min.js', $withoutMidi);
        $this->assertStringContainsString('/js/downloads.js', $withoutMidi);

        $this->makeAttachment($songId, 'Begleitung.mid', 'audio/midi');

        $withMidi = $this->renderIndexFor($fixture['user']);

        $this->assertStringContainsString('/vendor/tone/Tone.js', $withMidi);
        $this->assertStringContainsString('midi-player.min.js', $withMidi);
    }

    public function testRenewingTheWebdavTokenAsksForConfirmationButFirstIssueDoesNot(): void
    {
        $fixture = $this->makeMemberWithProjects(['Webdav ' . bin2hex(random_bytes(3)) => ['start' => date('Y-m-d')]]);
        $user = $fixture['user'];

        $first = $this->renderIndexFor($user);
        if (!str_contains($first, '/downloads/webdav-token')) {
            $this->markTestSkipped('WebDAV-Funktion ist in dieser Installation abgeschaltet.');
        }
        $this->assertStringNotContainsString('data-confirm=', $this->webdavForm($first));

        $controller = $this->container()->get(DownloadController::class);
        $controller->rotateWebdavToken($this->makeRequest('POST', '/downloads/webdav-token'), $this->makeResponse());

        // Direkt nach dem Erzeugen zeigt die Seite das Zugangswort; danach bleibt nur der Zustand "vergeben".
        $this->renderIndexFor($user);
        $renewal = $this->renderIndexFor($user);

        $form = $this->webdavForm($renewal);
        $this->assertStringContainsString('data-confirm="', $form);
        $this->assertStringContainsString('nicht mehr', $form);
    }

    private function webdavForm(string $html): string
    {
        $this->assertSame(
            1,
            preg_match('/<form[^>]*action="\/downloads\/webdav-token"[^>]*>/', $html, $matches)
        );

        return $matches[0];
    }
}
