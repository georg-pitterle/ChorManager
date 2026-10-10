<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Controllers\EvaluationController;
use App\Models\Project;
use App\Policies\ProjectMemberPolicy;
use App\Queries\ProjectQuery;
use App\Services\NameFormatterService;
use App\Util\PasswordHasher;
use Illuminate\Database\Capsule\Manager as Capsule;
use Illuminate\Database\Eloquent\Collection;
use PHPUnit\Framework\TestCase;
use Psr\Http\Message\ResponseInterface;
use Slim\Views\Twig;
use Tests\Unit\Bootstrap;

/**
 * Die Besetzungsansicht eines Projekts: Summen je Stimmgruppe stehen im Kopf,
 * die Vorlage folgt dem Gestaltungssystem (Überschriftenrollen, keine Schatten,
 * kein Hinweiskasten als Leerzustand, kein Neuladen bei jeder Pfeiltaste).
 */
class ProjectMembersVoicingFeatureTest extends TestCase
{
    use TestHttpHelpers;

    private int $userId = 0;
    private int $projectId = 0;

    protected function setUp(): void
    {
        parent::setUp();
        Bootstrap::setupTestDatabase();
        Bootstrap::getCapsule()?->connection()->beginTransaction();
        $_SESSION = [];

        $suffix = bin2hex(random_bytes(4));
        $this->userId = (int) Capsule::table('users')->insertGetId([
            'email' => 'voicing' . $suffix . '@example.test',
            'password' => PasswordHasher::hash('irrelevant'),
            'first_name' => 'Voicing',
            'last_name' => 'Person',
            'is_active' => 1,
        ]);
        $this->projectId = (int) Capsule::table('projects')->insertGetId([
            'name' => 'Besetzung ' . $suffix,
        ]);
    }

    protected function tearDown(): void
    {
        $connection = Bootstrap::getCapsule()?->connection();
        if ($connection !== null && $connection->transactionLevel() > 0) {
            $connection->rollBack();
        }

        $_SESSION = [];
        parent::tearDown();
    }

    /**
     * @param array<string,array<string,list<array<string,mixed>>>> $grouped
     * @return array<string,mixed>
     */
    private function renderData(array $grouped): array
    {
        $_SESSION['user_id'] = $this->userId;
        $_SESSION['can_manage_attendance_all'] = true;

        $captured = [];
        $twig = $this->createMock(Twig::class);
        $twig->expects($this->once())
            ->method('render')
            ->willReturnCallback(
                function ($response, $template, $data) use (&$captured): ResponseInterface {
                    $captured = $data;
                    return $response;
                }
            );

        $projectQuery = $this->createStub(ProjectQuery::class);
        $projectQuery->method('getProjectMembersGroupedByVoice')->willReturn($grouped);
        $projectQuery->method('getAccessibleProjects')
            ->willReturnCallback(static fn (): Collection => Project::orderBy('name')->get());

        $controller = new EvaluationController(
            $twig,
            $projectQuery,
            new NameFormatterService(),
            new ProjectMemberPolicy($_SESSION)
        );
        $controller->projectMembers(
            $this->makeRequest('GET', '/evaluations/project-members', [], ['project_id' => (string) $this->projectId]),
            $this->makeResponse()
        );

        return $captured;
    }

    /**
     * @return array<string,mixed>
     */
    private function member(int $id, bool $active = true): array
    {
        return ['id' => $id, 'first_name' => 'V' . $id, 'last_name' => 'N' . $id, 'is_active' => $active];
    }

    private function template(): string
    {
        $source = file_get_contents(dirname(__DIR__, 2) . '/templates/evaluations/project_members.twig');
        $this->assertIsString($source);

        return $source;
    }

    public function testControllerCountsMembersPerVoiceGroupAcrossSubVoices(): void
    {
        $data = $this->renderData([
            'Sopran' => [
                'Sopran 1' => [$this->member(1), $this->member(2)],
                'Sopran 2' => [$this->member(3, false)],
            ],
            'Bass' => ['_no_sub_voice' => [$this->member(4)]],
        ]);

        $this->assertSame(['Sopran' => 3, 'Bass' => 1], $data['voice_group_counts'] ?? null);
        $this->assertSame(4, $data['member_total'] ?? null);
        $this->assertSame(1, $data['archived_total'] ?? null);
    }

    public function testControllerReportsZeroTotalsForAnEmptyProject(): void
    {
        $data = $this->renderData([]);

        $this->assertSame([], $data['voice_group_counts'] ?? null);
        $this->assertSame(0, $data['member_total'] ?? null);
        $this->assertSame(0, $data['archived_total'] ?? null);
    }

    public function testTemplateHasHeadingHierarchyWithoutSkippedLevels(): void
    {
        $source = $this->template();

        $this->assertStringContainsString('<h1 ', $source);
        $this->assertStringContainsString('<h2 ', $source);
        $this->assertStringContainsString('<h3 ', $source);
        $this->assertStringNotContainsString('<h5', $source);
        // Teilstimmen sind Gruppenlabel-Überschriften, kein fettes strong.
        $this->assertStringContainsString('group-label', $source);
    }

    public function testTemplateJumpsToVoiceGroupsAndOffersTotals(): void
    {
        $source = $this->template();

        $this->assertStringContainsString('href="#voice-group-', $source);
        $this->assertStringContainsString('member_total', $source);
        $this->assertStringContainsString('voice_group_counts', $source);
    }

    public function testTemplateFollowsTheDesignSystemRules(): void
    {
        $source = $this->template();

        $this->assertStringNotContainsString('shadow-sm', $source, 'Das System ist flach.');
        $this->assertStringNotContainsString('alert-info', $source, 'Leerzustände sind gedämpfter Text.');
        $this->assertStringNotContainsString('<strong>', $source, 'Fett ist ein Ergebnis, kein Name.');
        // text-bg-light ist das Schlagwort-Badge des Systems, bg-light als Flächenfüllung nicht.
        $this->assertDoesNotMatchRegularExpression('/(?<!text-)bg-light/', $source);
        $this->assertStringNotContainsString('border-info', $source);
        $this->assertStringNotContainsString('class="card shadow', $source);
    }

    public function testTemplateDoesNotReloadOnEveryArrowKey(): void
    {
        $source = $this->template();

        $this->assertStringNotContainsString('onchange-submit', $source);
        $this->assertStringContainsString('type="submit"', $source);
    }

    public function testTemplateKeepsTheNotesIconDecorative(): void
    {
        $source = $this->template();

        $this->assertStringNotContainsString('bi-music-note-beamed', $source);
        $this->assertDoesNotMatchRegularExpression('/<i class="bi [^"]*"(?![^>]*aria-hidden)/', $source);
    }

    public function testTemplateUsesTheMenuNameAndTheInformalAddress(): void
    {
        $source = $this->template();

        $this->assertStringContainsString('Besetzung', $source);
        $this->assertStringNotContainsString('Projektübersicht', $source);
        $this->assertStringNotContainsString('Bitte wählen Sie', $source);
    }

    public function testQuotaLinkCarriesTheSelectedProject(): void
    {
        $this->assertMatchesRegularExpression(
            '#href="/evaluations\?project_id=\{\{ selected_project\.id \}\}"#',
            $this->template()
        );
    }

    public function testOutlineSecondaryButtonKeepsReadableContrastOnThePageBackground(): void
    {
        $css = file_get_contents(dirname(__DIR__, 2) . '/public/css/style.css');
        $this->assertIsString($css);

        $this->assertMatchesRegularExpression(
            '/\.btn-outline-secondary\s*\{[^}]*--bs-btn-color:\s*var\(--theme-text-muted\)/s',
            $css
        );
    }

    public function testPageActionControlsReachTheTouchTargetSizeOnPhones(): void
    {
        $css = file_get_contents(dirname(__DIR__, 2) . '/public/css/style.css');
        $this->assertIsString($css);

        $this->assertMatchesRegularExpression(
            '/\.page-header \.page-actions \.form-select[^{]*\{[^}]*min-height:\s*2\.75rem/s',
            $css
        );
    }
}
