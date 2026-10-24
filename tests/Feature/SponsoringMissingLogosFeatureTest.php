<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Controllers\ProjectController;
use App\Controllers\SponsoringDashboardController;
use App\Controllers\SponsorshipController;
use App\Models\Attachment;
use App\Models\Project;
use App\Models\Sponsor;
use App\Models\SponsorPackage;
use App\Models\Sponsorship;
use App\Models\User;
use App\Policies\SponsoringPolicy;
use App\Services\NameFormatterService;
use App\Util\PasswordHasher;
use App\Util\SponsorshipStatus;
use Carbon\Carbon;
use Illuminate\Database\Capsule\Manager as Capsule;
use PHPUnit\Framework\TestCase;
use Slim\Views\Twig;
use Tests\Unit\Bootstrap;

/**
 * Dashboard "Logo fehlt": von welchem zugesagten Sponsor fehlt das Logo bis
 * wann?
 *
 * Das Zusammensuchen der Logos vor dem Druck war der größte Aufwand der
 * Excel-Liste. Die Fristen stehen fest am Projekt (Druck und online), die
 * Vereinbarung sagt, wofür sie das Logo braucht.
 */
final class SponsoringMissingLogosFeatureTest extends TestCase
{
    use TestHttpHelpers;
    use TwigViewStubs;

    private User $manager;

    protected function setUp(): void
    {
        parent::setUp();
        Bootstrap::setupTestDatabase();
        Capsule::connection()->beginTransaction();

        $this->manager = User::create([
            'first_name' => 'Fristen',
            'last_name' => 'Testperson',
            'email' => 'logos-' . bin2hex(random_bytes(6)) . '@example.test',
            'password' => PasswordHasher::hash('x'),
            'is_active' => 1,
        ]);
        $this->loginAs(true);
    }

    protected function tearDown(): void
    {
        $connection = Capsule::connection();
        if ($connection->transactionLevel() > 0) {
            $connection->rollBack();
        }

        Carbon::setTestNow();
        $_SESSION = [];
        parent::tearDown();
    }

    public function testAnAcceptedAgreementWithLogoNeedAndNoLogoIsListed(): void
    {
        $project = $this->project('2026-06-10', null);
        $this->agreement('Logolos Druckerei', $project, print: true);

        $rows = $this->missingLogos();

        $this->assertCount(1, $rows);
        $this->assertSame('Logolos Druckerei', $rows[0]['sponsor_name']);
        $this->assertSame('10.06.2026', $rows[0]['deadline']);
        $this->assertSame('print', $rows[0]['kind']);
    }

    public function testASponsorWithLogoIsNotListed(): void
    {
        $sponsorship = $this->agreement('Mit Logo GmbH', $this->project('2026-06-10', null), print: true);
        Attachment::create([
            'entity_type' => Sponsor::LOGO_ENTITY_TYPE,
            'entity_id' => $sponsorship->sponsor_id,
            'filename' => 'x_logo.png',
            'original_name' => 'logo.png',
            'mime_type' => 'image/png',
            'file_size' => 4,
            'file_content' => 'test',
        ]);

        $this->assertSame([], $this->missingLogos());
    }

    public function testRequestedAgreementsAreNotListed(): void
    {
        $this->agreement('Nur angefragt', $this->project('2026-06-10', null), print: true, status: SponsorshipStatus::REQUESTED);

        $this->assertSame([], $this->missingLogos());
    }

    public function testAnAgreementWithoutLogoNeedIsNotListed(): void
    {
        $this->agreement('Ohne Logobedarf', $this->project('2026-06-10', null));

        $this->assertSame([], $this->missingLogos());
    }

    public function testASponsorWithTwoAgreementsAppearsOnceWithTheEarliestDeadline(): void
    {
        // Die frühere Frist zuerst und dazwischen eine spätere: So fällt auf,
        // wenn statt der frühesten einfach die zuletzt gelesene Zeile gewinnt.
        $sponsor = Sponsor::create(['name' => 'Zweifach Sponsor']);
        $this->agreement($sponsor, $this->project('2026-05-01', null), print: true);
        $this->agreement($sponsor, $this->project(null, '2026-09-01'), online: true);

        $rows = $this->missingLogos();

        $this->assertCount(1, $rows);
        $this->assertSame('01.05.2026', $rows[0]['deadline']);
    }

    public function testEntriesWithoutDeadlineComeLast(): void
    {
        $this->agreement('Aaa ohne Frist', null, print: true);
        $this->agreement('Zzz mit Frist', $this->project('2026-06-10', null), print: true);

        $rows = $this->missingLogos();

        $this->assertSame(['Zzz mit Frist', 'Aaa ohne Frist'], array_column($rows, 'sponsor_name'));
        $this->assertNull($rows[1]['deadline']);
        $this->assertSame('none', $rows[1]['urgency']);
    }

    public function testOverdueAndSoonAreMarked(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-06-01'));
        $this->agreement('Überfällig AG', $this->project('2026-05-20', null), print: true);
        $this->agreement('Bald dran KG', $this->project('2026-06-10', null), print: true);
        $this->agreement('Später OG', $this->project('2026-08-01', null), print: true);

        $html = $this->renderDashboard();

        $this->assertStringContainsString('Logo fehlt', $html);
        $this->assertStringContainsString('logo-deadline-overdue', $html);
        $this->assertStringContainsString('logo-deadline-soon', $html);
        $this->assertSame(
            ['overdue', 'soon', 'later'],
            array_column($this->missingLogos(), 'urgency')
        );
    }

    public function testANewAgreementTakesOverTheLogoNeedsOfItsPackage(): void
    {
        $package = SponsorPackage::create([
            'name' => 'Logopaket ' . bin2hex(random_bytes(4)),
            'min_amount' => '100.00',
            'color' => 'info',
            'needs_logo_print' => true,
            'needs_logo_online' => false,
        ]);
        $sponsor = Sponsor::create(['name' => 'Paketübernahme ' . bin2hex(random_bytes(4))]);

        $this->sponsorshipController()->create($this->makeRequest('POST', '/sponsoring/sponsorships', [
            'sponsor_id' => (string) $sponsor->id,
            'package_id' => (string) $package->id,
            'amount' => '100',
            'status' => SponsorshipStatus::ACCEPTED,
        ]), $this->makeResponse());

        $sponsorship = Sponsorship::where('sponsor_id', $sponsor->id)->firstOrFail();
        $this->assertTrue((bool) $sponsorship->needs_logo_print);
        $this->assertFalse((bool) $sponsorship->needs_logo_online);
    }

    public function testTheFormDecidesOverThePackageOnceItSendsTheLogoNeeds(): void
    {
        $package = SponsorPackage::create([
            'name' => 'Logopaket ' . bin2hex(random_bytes(4)),
            'min_amount' => '100.00',
            'color' => 'info',
            'needs_logo_print' => true,
        ]);
        $sponsor = Sponsor::create(['name' => 'Formular entscheidet ' . bin2hex(random_bytes(4))]);

        $this->sponsorshipController()->create($this->makeRequest('POST', '/sponsoring/sponsorships', [
            'sponsor_id' => (string) $sponsor->id,
            'package_id' => (string) $package->id,
            'amount' => '100',
            'status' => SponsorshipStatus::ACCEPTED,
            'logo_needs' => '1',
            'needs_logo_online' => '1',
        ]), $this->makeResponse());

        $sponsorship = Sponsorship::where('sponsor_id', $sponsor->id)->firstOrFail();
        $this->assertFalse((bool) $sponsorship->needs_logo_print);
        $this->assertTrue((bool) $sponsorship->needs_logo_online);
    }

    public function testTheSponsoringManagerSetsTheProjectDeadlines(): void
    {
        $project = $this->project(null, null);

        $response = $this->dashboardController($this->createStub(Twig::class))->updateLogoDeadlines(
            $this->makeRequest('POST', '/sponsoring/projects/' . $project->id . '/logo-deadlines', [
                'logo_deadline_print' => '2026-06-10',
                'logo_deadline_online' => '2026-07-01',
            ]),
            $this->makeResponse(),
            ['id' => (string) $project->id]
        );

        $this->assertRedirect($response, '/sponsoring');
        $fresh = $project->fresh();
        $this->assertSame('2026-06-10', $fresh->logo_deadline_print?->format('Y-m-d'));
        $this->assertSame('2026-07-01', $fresh->logo_deadline_online?->format('Y-m-d'));
    }

    public function testAnInvalidDeadlineIsRejected(): void
    {
        $project = $this->project('2026-06-10', null);

        $this->dashboardController($this->createStub(Twig::class))->updateLogoDeadlines(
            $this->makeRequest('POST', '/sponsoring/projects/' . $project->id . '/logo-deadlines', [
                'logo_deadline_print' => '10.06.2026',
                'logo_deadline_online' => '',
            ]),
            $this->makeResponse(),
            ['id' => (string) $project->id]
        );

        $this->assertNotNull($_SESSION['error'] ?? null);
        $this->assertSame('2026-06-10', $project->fresh()->logo_deadline_print?->format('Y-m-d'));
    }

    public function testAContributorMayNotSetProjectDeadlines(): void
    {
        $project = $this->project(null, null);
        $this->loginAs(false);

        $response = $this->dashboardController($this->createStub(Twig::class))->updateLogoDeadlines(
            $this->makeRequest('POST', '/sponsoring/projects/' . $project->id . '/logo-deadlines', [
                'logo_deadline_print' => '2026-06-10',
            ]),
            $this->makeResponse(),
            ['id' => (string) $project->id]
        );

        $this->assertSame(403, $response->getStatusCode());
        $this->assertNull($project->fresh()->logo_deadline_print);
    }

    public function testTheProjectFormStoresLogoDeadlines(): void
    {
        $project = $this->project(null, null);

        (new ProjectController(...$this->projectControllerArguments()))->update(
            $this->makeRequest('POST', '/projects/' . $project->id . '/update', [
                'name' => $project->name,
                'logo_deadline_print' => '2026-06-10',
                'logo_deadline_online' => '',
            ]),
            $this->makeResponse(),
            ['id' => (string) $project->id]
        );

        $fresh = $project->fresh();
        $this->assertSame('2026-06-10', $fresh->logo_deadline_print?->format('Y-m-d'));
        $this->assertNull($fresh->logo_deadline_online);
    }

    public function testTheManagerSeesTheDeadlineFormOfProjectsWithLogoNeed(): void
    {
        $project = $this->project('2026-06-10', null);
        $this->agreement('Fristformular', $project, print: true);

        $html = $this->renderDashboard();

        $this->assertStringContainsString('action="/sponsoring/projects/' . $project->id . '/logo-deadlines"', $html);
        $this->assertStringContainsString('value="2026-06-10"', $html);
    }

    public function testAContributorDoesNotSeeTheDeadlineForm(): void
    {
        $this->agreement('Fristformular', $this->project('2026-06-10', null), print: true);
        $this->loginAs(false);

        $this->assertStringNotContainsString('/logo-deadlines"', $this->renderDashboard());
    }

    public function testTheAgreementFormsSendTheLogoNeeds(): void
    {
        $sponsorship = $this->agreement('Formularhäkchen', null, print: true);
        SponsorPackage::create([
            'name' => 'Formularpaket ' . bin2hex(random_bytes(4)),
            'min_amount' => '100.00',
            'color' => 'info',
            'needs_logo_print' => true,
        ]);

        $response = (new \App\Controllers\SponsorController(
            $this->createAppTwig('/sponsoring/sponsors'),
            new SponsoringPolicy($_SESSION),
            $this->attachmentService()
        ))->detail(
            $this->makeRequest('GET', '/sponsoring/sponsors/' . $sponsorship->sponsor_id),
            $this->makeResponse(),
            ['id' => (string) $sponsorship->sponsor_id]
        );
        $html = (string) $response->getBody();

        $this->assertSame(2, substr_count($html, 'name="logo_needs" value="1"'));
        $this->assertStringContainsString('name="needs_logo_print" value="1" checked', $html);
        $this->assertStringContainsString('data-needs-logo-print=', $html);
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function missingLogos(): array
    {
        $method = new \ReflectionMethod(SponsoringDashboardController::class, 'missingLogos');

        return $method->invoke($this->dashboardController($this->createStub(Twig::class)));
    }

    private function renderDashboard(): string
    {
        $response = $this->dashboardController($this->createAppTwig('/sponsoring'))->index(
            $this->makeRequest('GET', '/sponsoring'),
            $this->makeResponse()
        );

        return (string) $response->getBody();
    }

    private function dashboardController(Twig $twig): SponsoringDashboardController
    {
        return new SponsoringDashboardController($twig, new NameFormatterService(), new SponsoringPolicy($_SESSION));
    }

    private function sponsorshipController(): SponsorshipController
    {
        return new SponsorshipController(new SponsoringPolicy($_SESSION), $this->attachmentService());
    }

    /**
     * @return list<mixed>
     */
    private function projectControllerArguments(): array
    {
        $constructor = (new \ReflectionClass(ProjectController::class))->getConstructor();
        $arguments = [];
        foreach ($constructor?->getParameters() ?? [] as $parameter) {
            $type = $parameter->getType();
            $arguments[] = $type instanceof \ReflectionNamedType && !$type->isBuiltin()
                ? $this->createStub($type->getName())
                : null;
        }

        return $arguments;
    }

    private function project(?string $print, ?string $online): Project
    {
        return Project::create([
            'name' => 'Logoprojekt ' . bin2hex(random_bytes(4)),
            'logo_deadline_print' => $print,
            'logo_deadline_online' => $online,
        ]);
    }

    private function agreement(
        Sponsor|string $sponsor,
        ?Project $project,
        bool $print = false,
        bool $online = false,
        string $status = SponsorshipStatus::ACCEPTED
    ): Sponsorship {
        if (is_string($sponsor)) {
            $sponsor = Sponsor::create(['name' => $sponsor]);
        }

        return Sponsorship::create([
            'sponsor_id' => $sponsor->id,
            'project_id' => $project?->id,
            'amount' => '100.00',
            'status' => $status,
            'needs_logo_print' => $print,
            'needs_logo_online' => $online,
            'created_by_user_id' => $this->manager->id,
        ]);
    }

    private function loginAs(bool $manager): void
    {
        $_SESSION = [
            'user_id' => (int) $this->manager->id,
            'can_manage_sponsoring' => $manager,
            'can_create_own_sponsorships' => !$manager,
        ];
    }
}
