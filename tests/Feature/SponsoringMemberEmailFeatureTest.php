<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Controllers\SponsorController;
use App\Controllers\SponsoringContactController;
use App\Controllers\SponsoringDashboardController;
use App\Models\Project;
use App\Models\Role;
use App\Models\Sponsor;
use App\Models\SponsoringContact;
use App\Models\Sponsorship;
use App\Models\User;
use App\Policies\SponsoringPolicy;
use App\Services\NameFormatterService;
use App\Util\PasswordHasher;
use App\Util\SponsorshipStatus;
use Illuminate\Database\Capsule\Manager as Capsule;
use PHPUnit\Framework\TestCase;
use Psr\Http\Message\ResponseInterface;
use Slim\Views\Twig;
use Tests\Unit\Bootstrap;

/**
 * Wer das Sponsoring verwaltet, erreicht die Zuständigen und die Verfasser von
 * Kontakten per Mail: Ihr Name ist ein mailto-Link mit vorbelegtem Betreff.
 * Beitragende sehen fremde Adressen nicht.
 *
 * Das Sponsoring-Team kennt nicht jedes Chormitglied persönlich und hatte bisher
 * nur den Namen - um nachzufragen, musste es die Adresse anderswo suchen.
 */
final class SponsoringMemberEmailFeatureTest extends TestCase
{
    use TestHttpHelpers;
    use TwigViewStubs;

    private User $assignee;
    private User $contributor;
    private Sponsor $sponsor;
    private Sponsorship $commitment;

    protected function setUp(): void
    {
        parent::setUp();
        Bootstrap::setupTestDatabase();
        Capsule::connection()->beginTransaction();

        $this->assignee = $this->person('Zuständig');
        $this->contributor = $this->person('Beitragend');
        $role = Role::create(['name' => 'Sponsoring-Mail ' . bin2hex(random_bytes(3)), 'can_create_own_sponsorships' => 1]);
        $this->assignee->roles()->attach($role->id);

        $this->sponsor = Sponsor::create(['name' => 'Musikhaus Mailtest']);
        $project = Project::create(['name' => 'Herbstkonzert']);
        $this->commitment = Sponsorship::create([
            'sponsor_id' => $this->sponsor->id,
            'project_id' => $project->id,
            'amount' => '300.00',
            'status' => SponsorshipStatus::ACCEPTED,
            'assigned_user_id' => $this->assignee->id,
            'created_by_user_id' => $this->contributor->id,
        ]);
        SponsoringContact::create([
            'sponsor_id' => $this->sponsor->id,
            'sponsorship_id' => $this->commitment->id,
            'user_id' => $this->assignee->id,
            'contact_date' => date('Y-m-d'),
            'type' => 'call',
            'summary' => 'Rückruf vereinbart.',
            'follow_up_date' => date('Y-m-d', strtotime('+1 day')),
            'follow_up_done' => 0,
        ]);
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

    public function testTheManagerCanMailTheAssigneeFromTheSponsorPage(): void
    {
        $_SESSION = ['user_id' => (int) $this->contributor->id, 'can_manage_sponsoring' => true];

        $html = $this->renderSponsorPage();

        $this->assertStringContainsString('href="mailto:' . $this->assignee->email . '?subject=', $html);
        $this->assertStringContainsString(rawurlencode('Sponsoring: Musikhaus Mailtest – Herbstkonzert'), $html);
    }

    public function testAContributorDoesNotSeeTheAddress(): void
    {
        $_SESSION = ['user_id' => (int) $this->contributor->id, 'can_create_own_sponsorships' => true];

        $html = $this->renderSponsorPage();

        $this->assertStringNotContainsString((string) $this->assignee->email, $html);
        $this->assertStringContainsString('Zuständig', $html);
    }

    public function testTheOverviewHandsOutAddressesOnlyToTheManager(): void
    {
        $_SESSION = ['user_id' => (int) $this->contributor->id, 'can_manage_sponsoring' => true];
        $manager = $this->dashboardData();

        $this->assertSame($this->assignee->email, $this->rowFor($manager['unpaid'])['assignee_email']);
        $this->assertSame($this->assignee->email, $this->rowFor($manager['upcoming_follow_ups'])['owner_email']);

        $_SESSION = ['user_id' => (int) $this->assignee->id, 'can_create_own_sponsorships' => true];
        $contributor = $this->dashboardData();

        $this->assertNull($this->rowFor($contributor['upcoming_follow_ups'])['owner_email']);
        $this->assertNull($this->rowFor($contributor['upcoming_follow_ups'])['assignee_email']);
    }

    public function testTheContactsPageHandsOutAddressesOnlyToTheManager(): void
    {
        $_SESSION = ['user_id' => (int) $this->contributor->id, 'can_manage_sponsoring' => true];
        $this->assertSame($this->assignee->email, $this->rowFor($this->contactRows())['owner_email']);

        $_SESSION = ['user_id' => (int) $this->assignee->id, 'can_create_own_sponsorships' => true];
        $this->assertNull($this->rowFor($this->contactRows())['owner_email']);
    }

    private function renderSponsorPage(): string
    {
        $controller = new SponsorController(
            $this->createAppTwig('/sponsoring/sponsors/' . $this->sponsor->id),
            new SponsoringPolicy($_SESSION),
            $this->attachmentService()
        );

        return (string) $controller->detail(
            $this->makeRequest('GET', '/sponsoring/sponsors/' . $this->sponsor->id),
            $this->makeResponse(),
            ['id' => (string) $this->sponsor->id]
        )->getBody();
    }

    /**
     * @return array<string, mixed>
     */
    private function dashboardData(): array
    {
        $captured = [];
        (new SponsoringDashboardController($this->capturingTwig($captured), new NameFormatterService(), new SponsoringPolicy($_SESSION)))
            ->index($this->makeRequest('GET', '/sponsoring'), $this->makeResponse());

        return $captured;
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function contactRows(): array
    {
        $captured = [];
        (new SponsoringContactController($this->capturingTwig($captured), new SponsoringPolicy($_SESSION)))
            ->index($this->makeRequest('GET', '/sponsoring/contacts'), $this->makeResponse());

        return $captured['contacts'];
    }

    /**
     * @param array<string, mixed> $captured
     */
    private function capturingTwig(array &$captured): Twig
    {
        $twig = $this->createStub(Twig::class);
        $twig->method('render')->willReturnCallback(
            function ($response, string $template, array $data) use (&$captured): ResponseInterface {
                $captured = $data;
                return $response;
            }
        );

        return $twig;
    }

    /**
     * @param list<array<string, mixed>> $rows
     * @return array<string, mixed>
     */
    private function rowFor(array $rows): array
    {
        foreach ($rows as $row) {
            if (($row['sponsor_name'] ?? null) === $this->sponsor->name) {
                return $row;
            }
        }

        $this->fail('Keine Zeile für den Testsponsor.');
    }

    private function person(string $firstName): User
    {
        return User::create([
            'first_name' => $firstName,
            'last_name' => 'Mailtest',
            'email' => 'sponsoring-mail-' . bin2hex(random_bytes(5)) . '@example.test',
            'password' => PasswordHasher::hash('x'),
            'is_active' => 1,
        ]);
    }
}
