<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Controllers\SponsorController;
use App\Controllers\SponsorPackageController;
use App\Controllers\SponsorshipController;
use App\Middleware\RoleMiddleware;
use App\Models\Project;
use App\Models\Sponsor;
use App\Models\SponsorPackage;
use App\Models\Sponsorship;
use App\Models\SponsoringContact;
use App\Models\User;
use App\Controllers\SponsoringContactController;
use App\Models\Attachment;
use App\Policies\SponsoringPolicy;
use App\Util\SponsorshipStatus;
use PHPUnit\Framework\TestCase;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\RequestHandlerInterface;
use Slim\Psr7\Response as SlimResponse;
use Slim\Views\Twig;
use Tests\Unit\Bootstrap;

/**
 * Deckt das Recht can_create_own_sponsorships ab: ein Mitglied ohne
 * Sponsoring-Verwaltung darf lesen und eigene Vereinbarungen pflegen, aber
 * nichts Fremdes anfassen.
 */
class SponsoringPermissionsFeatureTest extends TestCase
{
    use TestHttpHelpers;

    private User $contributor;
    private User $otherUser;

    protected function setUp(): void
    {
        parent::setUp();
        $_SESSION = [];
        Bootstrap::setupTestDatabase();

        $this->contributor = $this->createUser();
        $this->otherUser = $this->createUser();
    }

    protected function tearDown(): void
    {
        $this->contributor->delete();
        $this->otherUser->delete();
        $_SESSION = [];
        parent::tearDown();
    }

    public function testMiddlewareLetsAContributorIntoTheSponsoringAreaButNotIntoTheManagementRoutes(): void
    {
        $this->loginAsContributor();

        $accessMiddleware = new RoleMiddleware(requiresSponsoringAccess: true);
        $this->assertSame(
            204,
            $accessMiddleware->process($this->makeRequest('GET', '/sponsoring'), $this->passThrough())->getStatusCode()
        );

        $managementMiddleware = new RoleMiddleware(requiresSponsoringManagement: true);
        $this->assertSame(
            403,
            $managementMiddleware->process(
                $this->makeRequest('POST', '/sponsoring/packages'),
                $this->passThrough()
            )->getStatusCode()
        );
    }

    public function testMiddlewareStillLocksOutMembersWithoutAnySponsoringRight(): void
    {
        $_SESSION['user_id'] = (int) $this->contributor->id;

        $accessMiddleware = new RoleMiddleware(requiresSponsoringAccess: true);

        $this->assertSame(
            403,
            $accessMiddleware->process($this->makeRequest('GET', '/sponsoring'), $this->passThrough())->getStatusCode()
        );
    }

    public function testContributorCreatesAnAgreementAndIsStoredAsItsAuthor(): void
    {
        $this->loginAsContributor();
        $sponsor = $this->makeSponsor();

        try {
            $response = $this->sponsorshipController()->create($this->makeRequest('POST', '/sponsoring/sponsorships', [
                'sponsor_id' => (string) $sponsor->id,
                'amount' => '500',
                'status' => SponsorshipStatus::REQUESTED,
            ]), $this->makeResponse());

            $this->assertRedirect($response, '/sponsoring/sponsors/' . $sponsor->id);

            $sponsorship = Sponsorship::where('sponsor_id', $sponsor->id)->firstOrFail();
            $this->assertSame((int) $this->contributor->id, (int) $sponsorship->created_by_user_id);
        } finally {
            $this->cleanUp($sponsor);
        }
    }

    public function testContributorMayEditTheirOwnAgreementButNotSomeoneElsesAgreement(): void
    {
        $this->loginAsContributor();
        $sponsor = $this->makeSponsor();

        try {
            $own = $this->makeSponsorship($sponsor, (int) $this->contributor->id);
            $foreign = $this->makeSponsorship($sponsor, (int) $this->otherUser->id);
            $controller = $this->sponsorshipController();

            $ownResponse = $controller->update(
                $this->makeRequest('POST', '/sponsoring/sponsorships/' . $own->id, [
                    'sponsor_id' => (string) $sponsor->id,
                    'amount' => '750',
                    'status' => SponsorshipStatus::ACCEPTED,
                ]),
                $this->makeResponse(),
                ['id' => (string) $own->id]
            );
            $this->assertRedirect($ownResponse, '/sponsoring/sponsors/' . $sponsor->id);
            $this->assertSame(SponsorshipStatus::ACCEPTED, $own->fresh()->status);

            $foreignResponse = $controller->update(
                $this->makeRequest('POST', '/sponsoring/sponsorships/' . $foreign->id, [
                    'sponsor_id' => (string) $sponsor->id,
                    'amount' => '750',
                    'status' => SponsorshipStatus::ACCEPTED,
                ]),
                $this->makeResponse(),
                ['id' => (string) $foreign->id]
            );
            $this->assertSame(403, $foreignResponse->getStatusCode());
            $this->assertSame(SponsorshipStatus::REQUESTED, $foreign->fresh()->status);
        } finally {
            $this->cleanUp($sponsor);
        }
    }

    public function testAgreementsWithoutAnAuthorStayWithTheSponsoringTeam(): void
    {
        $this->loginAsContributor();
        $sponsor = $this->makeSponsor();

        try {
            // Bestand aus der Zeit, in der nur das Sponsoring-Team schreiben
            // konnte: ohne Urheber bleibt der Eintrag dem Vollrecht vorbehalten.
            $legacy = $this->makeSponsorship($sponsor, null);

            $response = $this->sponsorshipController()->update(
                $this->makeRequest('POST', '/sponsoring/sponsorships/' . $legacy->id, [
                    'sponsor_id' => (string) $sponsor->id,
                    'amount' => '900',
                    'status' => SponsorshipStatus::ACCEPTED,
                ]),
                $this->makeResponse(),
                ['id' => (string) $legacy->id]
            );

            $this->assertSame(403, $response->getStatusCode());
        } finally {
            $this->cleanUp($sponsor);
        }
    }

    public function testContributorMayNotDeleteASponsor(): void
    {
        $this->loginAsContributor();
        $sponsor = $this->makeSponsor();

        try {
            $response = $this->sponsorController()->delete(
                $this->makeRequest('POST', '/sponsoring/sponsors/' . $sponsor->id . '/delete'),
                $this->makeResponse(),
                ['id' => (string) $sponsor->id]
            );

            $this->assertSame(403, $response->getStatusCode());
            $this->assertNotNull(Sponsor::find($sponsor->id));
        } finally {
            $this->cleanUp($sponsor);
        }
    }

    public function testContributorMayNotCreateAPackage(): void
    {
        $this->loginAsContributor();
        $name = 'Testpaket ' . bin2hex(random_bytes(4));

        $controller = new SponsorPackageController($this->createStub(Twig::class), new SponsoringPolicy());
        $middleware = new RoleMiddleware(requiresSponsoringManagement: true);

        // Die Route liegt hinter der Verwaltungs-Middleware; der Controller
        // wird fuer einen Beitragenden gar nicht erst erreicht.
        $response = $middleware->process(
            $this->makeRequest('POST', '/sponsoring/packages', ['name' => $name]),
            $this->passThrough()
        );

        $this->assertSame(403, $response->getStatusCode());
        $this->assertSame(0, SponsorPackage::where('name', $name)->count());
        $this->assertInstanceOf(SponsorPackageController::class, $controller);
    }

    public function testContributorMayOnlyAttachAgreementsToARunningProject(): void
    {
        $this->loginAsContributor();
        $sponsor = $this->makeSponsor();
        $running = Project::create([
            'name' => 'Laufendes Projekt ' . bin2hex(random_bytes(4)),
            'start_date' => date('Y-m-d', strtotime('-1 month')),
            'end_date' => date('Y-m-d', strtotime('+1 month')),
        ]);
        $past = Project::create([
            'name' => 'Vergangenes Projekt ' . bin2hex(random_bytes(4)),
            'start_date' => date('Y-m-d', strtotime('-2 years')),
            'end_date' => date('Y-m-d', strtotime('-1 year')),
        ]);

        try {
            $policy = new SponsoringPolicy();
            $this->assertTrue($policy->canUseProject((int) $running->id));
            $this->assertTrue($policy->canUseProject(null));
            $this->assertFalse($policy->canUseProject((int) $past->id));

            $response = $this->sponsorshipController()->create(
                $this->makeRequest('POST', '/sponsoring/sponsorships', [
                    'sponsor_id' => (string) $sponsor->id,
                    'amount' => '400',
                    'project_id' => (string) $past->id,
                ]),
                $this->makeResponse()
            );

            $this->assertRedirect($response, '/sponsoring/sponsors/' . $sponsor->id);
            $this->assertSame(SponsorshipController::PROJECT_ERROR, $_SESSION['error'] ?? null);
            $this->assertSame(0, Sponsorship::where('sponsor_id', $sponsor->id)->count());

            // Das Sponsoring-Team ist an laufende Projekte nicht gebunden.
            $_SESSION['can_manage_sponsoring'] = true;
            $this->assertTrue((new SponsoringPolicy())->canUseProject((int) $past->id));
        } finally {
            $this->cleanUp($sponsor);
            $running->delete();
            $past->delete();
        }
    }

    public function testAgreementInheritsTheProjectPeriodWhenNoDatesAreGiven(): void
    {
        $this->loginAsContributor();
        $sponsor = $this->makeSponsor();
        $start = date('Y-m-d', strtotime('-1 month'));
        $end = date('Y-m-d', strtotime('+1 month'));
        $project = Project::create([
            'name' => 'Zeitraum-Projekt ' . bin2hex(random_bytes(4)),
            'start_date' => $start,
            'end_date' => $end,
        ]);

        try {
            $this->sponsorshipController()->create(
                $this->makeRequest('POST', '/sponsoring/sponsorships', [
                    'sponsor_id' => (string) $sponsor->id,
                    'amount' => '600',
                    'project_id' => (string) $project->id,
                ]),
                $this->makeResponse()
            );

            $sponsorship = Sponsorship::where('sponsor_id', $sponsor->id)->firstOrFail();
            $this->assertSame($start, $sponsorship->start_date->format('Y-m-d'));
            $this->assertSame($end, $sponsorship->end_date->format('Y-m-d'));
        } finally {
            $this->cleanUp($sponsor);
            $project->delete();
        }
    }

    public function testBlockedSponsorRefusesNewAgreementsAndContacts(): void
    {
        $this->loginAsContributor();
        $sponsor = $this->makeSponsor();
        $sponsor->update([
            'requests_blocked' => true,
            'requests_blocked_note' => 'Bittet ausdrücklich darum, nicht erneut angefragt zu werden.',
        ]);

        try {
            $agreement = $this->sponsorshipController()->create(
                $this->makeRequest('POST', '/sponsoring/sponsorships', [
                    'sponsor_id' => (string) $sponsor->id,
                    'amount' => '300',
                ]),
                $this->makeResponse()
            );

            $this->assertRedirect($agreement, '/sponsoring/sponsors/' . $sponsor->id);
            $this->assertSame(SponsorshipController::BLOCKED_ERROR, $_SESSION['error'] ?? null);
            $this->assertSame(0, Sponsorship::where('sponsor_id', $sponsor->id)->count());

            $contactController = new SponsoringContactController(
                $this->createStub(Twig::class),
                new SponsoringPolicy()
            );

            $contact = $contactController->create(
                $this->makeRequest('POST', '/sponsoring/contacts', [
                    'sponsor_id' => (string) $sponsor->id,
                    'contact_date' => date('Y-m-d'),
                    'type' => 'call',
                    'summary' => 'Doch noch einmal nachgefragt.',
                ]),
                $this->makeResponse()
            );

            $this->assertRedirect($contact, '/sponsoring/sponsors/' . $sponsor->id);
            $this->assertSame(SponsoringContactController::BLOCKED_ERROR, $_SESSION['error'] ?? null);
            $this->assertSame(0, SponsoringContact::where('sponsor_id', $sponsor->id)->count());
        } finally {
            SponsoringContact::where('sponsor_id', $sponsor->id)->delete();
            $this->cleanUp($sponsor);
        }
    }

    public function testUpdateKeepsAClearedPeriodInsteadOfRefillingItFromTheProject(): void
    {
        $this->loginAsContributor();
        $sponsor = $this->makeSponsor();
        $project = Project::create([
            'name' => 'Zeitraum-Behalten ' . bin2hex(random_bytes(4)),
            'start_date' => date('Y-m-d', strtotime('-1 month')),
            'end_date' => date('Y-m-d', strtotime('+1 month')),
        ]);

        try {
            $controller = $this->sponsorshipController();
            $controller->create(
                $this->makeRequest('POST', '/sponsoring/sponsorships', [
                    'sponsor_id' => (string) $sponsor->id,
                    'amount' => '500',
                    'project_id' => (string) $project->id,
                ]),
                $this->makeResponse()
            );

            $sponsorship = Sponsorship::where('sponsor_id', $sponsor->id)->firstOrFail();
            $this->assertNotNull($sponsorship->start_date);

            // Leeren heißt "unbefristet" - eine Vorbelegung machte das unmöglich.
            $controller->update(
                $this->makeRequest('POST', '/sponsoring/sponsorships/' . $sponsorship->id, [
                    'sponsor_id' => (string) $sponsor->id,
                    'amount' => '500',
                    'project_id' => (string) $project->id,
                    'start_date' => '',
                    'end_date' => '',
                ]),
                $this->makeResponse(),
                ['id' => (string) $sponsorship->id]
            );

            $reloaded = $sponsorship->fresh();
            $this->assertNull($reloaded->start_date);
            $this->assertNull($reloaded->end_date);
        } finally {
            $this->cleanUp($sponsor);
            $project->delete();
        }
    }

    public function testRunningProjectWithoutAnEndDateStaysSelectable(): void
    {
        $this->loginAsContributor();
        $openEnded = Project::create([
            'name' => 'Offenes Projekt ' . bin2hex(random_bytes(4)),
            'start_date' => date('Y-m-d', strtotime('-1 month')),
            'end_date' => null,
        ]);

        try {
            $policy = new SponsoringPolicy();

            // Ein fehlendes Datum heißt "offen", nicht "nicht laufend".
            $this->assertTrue($policy->canUseProject((int) $openEnded->id));
            $this->assertTrue(
                $policy->selectableProjects()->contains(
                    static fn (Project $project): bool => (int) $project->id === (int) $openEnded->id
                )
            );
        } finally {
            $openEnded->delete();
        }
    }

    public function testContributorIsOnlyOfferedProjectsTheyMayActuallyUse(): void
    {
        $this->loginAsContributor();
        $past = Project::create([
            'name' => 'Abgeschlossenes Projekt ' . bin2hex(random_bytes(4)),
            'start_date' => date('Y-m-d', strtotime('-2 years')),
            'end_date' => date('Y-m-d', strtotime('-1 year')),
        ]);

        try {
            // Die Auswahl bot vorher alle Projekte an; wer eines davon nahm,
            // verlor beim Absenden das ganze ausgefüllte Formular.
            $offered = (new SponsoringPolicy())->selectableProjects();
            $this->assertFalse(
                $offered->contains(static fn (Project $project): bool => (int) $project->id === (int) $past->id)
            );

            $_SESSION['can_manage_sponsoring'] = true;
            $this->assertTrue(
                (new SponsoringPolicy())->selectableProjects()
                    ->contains(static fn (Project $project): bool => (int) $project->id === (int) $past->id)
            );
        } finally {
            $past->delete();
        }
    }

    public function testDeletingASponsorAlsoRemovesItsAttachments(): void
    {
        $_SESSION['user_id'] = (int) $this->contributor->id;
        $_SESSION['can_manage_sponsoring'] = true;

        $sponsor = $this->makeSponsor();
        $sponsorship = $this->makeSponsorship($sponsor, (int) $this->contributor->id);

        $sponsorAttachment = $this->makeAttachment('sponsor', (int) $sponsor->id);
        $agreementAttachment = $this->makeAttachment('sponsorship', (int) $sponsorship->id);

        $response = $this->sponsorController()->delete(
            $this->makeRequest('POST', '/sponsoring/sponsors/' . $sponsor->id . '/delete'),
            $this->makeResponse(),
            ['id' => (string) $sponsor->id]
        );

        $this->assertRedirect($response, '/sponsoring/sponsors');
        $this->assertNull(Sponsor::find($sponsor->id));

        // Ohne Aufräumen blieben die BLOB-Zeilen für immer unerreichbar liegen -
        // die Bestätigung verspricht aber, dass alles Verknüpfte mitgeht.
        $this->assertNull(Attachment::find($sponsorAttachment->id));
        $this->assertNull(Attachment::find($agreementAttachment->id));
    }

    private function makeAttachment(string $entityType, int $entityId): Attachment
    {
        $content = 'Testinhalt ' . bin2hex(random_bytes(4));

        return Attachment::create([
            'entity_type'   => $entityType,
            'entity_id'     => $entityId,
            'filename'      => bin2hex(random_bytes(8)) . '_test.txt',
            'original_name' => 'test.txt',
            'mime_type'     => 'text/plain',
            'file_size'     => strlen($content),
            'file_content'  => $content,
        ]);
    }

    private function loginAsContributor(): void
    {
        $_SESSION['user_id'] = (int) $this->contributor->id;
        $_SESSION['can_manage_sponsoring'] = false;
        $_SESSION['can_create_own_sponsorships'] = true;
    }

    private function sponsorshipController(): SponsorshipController
    {
        return new SponsorshipController(new SponsoringPolicy(), $this->attachmentService());
    }

    private function sponsorController(): SponsorController
    {
        return new SponsorController(
            $this->createStub(Twig::class),
            new SponsoringPolicy(),
            $this->attachmentService()
        );
    }

    private function passThrough(): RequestHandlerInterface
    {
        return new class implements RequestHandlerInterface {
            public function handle(ServerRequestInterface $request): ResponseInterface
            {
                return (new SlimResponse())->withStatus(204);
            }
        };
    }

    private function createUser(): User
    {
        return User::create([
            'first_name' => 'Sponsoring',
            'last_name' => 'Testperson',
            'email' => 'sponsoring-' . bin2hex(random_bytes(6)) . '@example.test',
            'password' => password_hash('x', PASSWORD_DEFAULT),
            'is_active' => 1,
        ]);
    }

    private function makeSponsor(): Sponsor
    {
        return Sponsor::create(['name' => 'Rechtetest ' . bin2hex(random_bytes(4))]);
    }

    private function makeSponsorship(Sponsor $sponsor, ?int $createdBy): Sponsorship
    {
        return Sponsorship::create([
            'sponsor_id' => $sponsor->id,
            'amount' => '100.00',
            'status' => SponsorshipStatus::REQUESTED,
            'created_by_user_id' => $createdBy,
        ]);
    }

    private function cleanUp(Sponsor $sponsor): void
    {
        Sponsorship::where('sponsor_id', $sponsor->id)->delete();
        $sponsor->delete();
    }
}
