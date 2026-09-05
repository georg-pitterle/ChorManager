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
use App\Controllers\SponsoringAttachmentController;
use App\Controllers\SponsoringContactController;
use App\Controllers\SponsoringDashboardController;
use App\Models\Attachment;
use App\Services\NameFormatterService;
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

    /**
     * Das Projekt einer eigenen Vereinbarung bleibt im Bearbeiten-Formular
     * stehen, auch wenn es inzwischen abgeschlossen ist. Ohne diesen Zusatz
     * fehlte im Auswahlfeld genau der Eintrag, der gerade galt: der Browser
     * schickte "Kein Projekt", und ein Speichern aus einem anderen Grund - etwa
     * ein korrigierter Betrag - löste die Vereinbarung stillschweigend vom
     * Projekt.
     */
    public function testContributorKeepsTheFinishedProjectTheirOwnAgreementAlreadyHangsOn(): void
    {
        $this->loginAsContributor();
        $sponsor = $this->makeSponsor();
        $past = Project::create([
            'name' => 'Abgeschlossenes Projekt ' . bin2hex(random_bytes(4)),
            'start_date' => date('Y-m-d', strtotime('-2 years')),
            'end_date' => date('Y-m-d', strtotime('-1 year')),
        ]);
        $running = Project::create([
            'name' => 'Laufendes Projekt ' . bin2hex(random_bytes(4)),
            'start_date' => date('Y-m-d', strtotime('-1 month')),
            'end_date' => date('Y-m-d', strtotime('+1 month')),
        ]);

        try {
            $onPast = $this->makeSponsorship($sponsor, (int) $this->contributor->id);
            $onPast->project_id = $past->id;
            $onPast->save();

            $onRunning = $this->makeSponsorship($sponsor, (int) $this->contributor->id);
            $onRunning->project_id = $running->id;
            $onRunning->save();

            $withoutProject = $this->makeSponsorship($sponsor, (int) $this->contributor->id);

            $policy = new SponsoringPolicy();
            $sponsorships = Sponsorship::with('project')->where('sponsor_id', $sponsor->id)->get();
            $retained = $policy->retainedProjects($sponsorships, $policy->selectableProjects());

            $this->assertArrayHasKey((int) $onPast->id, $retained);
            $this->assertSame((int) $past->id, (int) $retained[(int) $onPast->id]->id);

            // Ein laufendes Projekt steht ohnehin in der Auswahl, eine
            // Vereinbarung ohne Projekt braucht keinen Zusatz.
            $this->assertArrayNotHasKey((int) $onRunning->id, $retained);
            $this->assertArrayNotHasKey((int) $withoutProject->id, $retained);

            // Das Vollrecht sieht jedes Projekt schon in der regulären Auswahl.
            $_SESSION['can_manage_sponsoring'] = true;
            $managerPolicy = new SponsoringPolicy();
            $this->assertSame(
                [],
                $managerPolicy->retainedProjects($sponsorships, $managerPolicy->selectableProjects())
            );
        } finally {
            $this->cleanUp($sponsor);
            $past->delete();
            $running->delete();
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

    public function testAttachmentOverviewShowsAContributorOnlyTheirOwnFiles(): void
    {
        $this->loginAsContributor();
        $sponsor = $this->makeSponsor();
        $own = $this->makeSponsorship($sponsor, (int) $this->contributor->id);
        $foreign = $this->makeSponsorship($sponsor, (int) $this->otherUser->id);

        $ownFile = $this->makeAttachment('sponsorship', (int) $own->id);
        $foreignFile = $this->makeAttachment('sponsorship', (int) $foreign->id);

        try {
            $rows = $this->attachmentOverviewRows();
            $ids = array_column($rows, 'id');

            // Sonst waere die Uebersicht der bequemste Weg an fremde Vertraege.
            $this->assertContains((int) $ownFile->id, $ids);
            $this->assertNotContains((int) $foreignFile->id, $ids);

            $_SESSION['can_manage_sponsoring'] = true;
            $idsForManager = array_column($this->attachmentOverviewRows(), 'id');
            $this->assertContains((int) $foreignFile->id, $idsForManager);
        } finally {
            Attachment::whereIn('id', [$ownFile->id, $foreignFile->id])->delete();
            $this->cleanUp($sponsor);
        }
    }

    public function testDashboardShowsAContributorOnlyTheirOwnContactsAndNoTotals(): void
    {
        $this->loginAsContributor();
        $sponsor = $this->makeSponsor();
        $sponsorship = $this->makeSponsorship($sponsor, (int) $this->contributor->id);

        $mine = $this->makeContact($sponsor, $sponsorship, (int) $this->contributor->id, 'Eigener Anruf');
        $theirs = $this->makeContact($sponsor, $sponsorship, (int) $this->otherUser->id, 'Fremder Anruf');

        try {
            $data = $this->dashboardData();

            $followUpOwners = array_column($data['upcoming_follow_ups'], 'owner_name');
            $recentSummaries = array_column($data['recent_contacts'], 'summary');

            $this->assertContains('Eigener Anruf', $recentSummaries);
            $this->assertNotContains('Fremder Anruf', $recentSummaries);
            $this->assertCount(1, $followUpOwners);

            // Summen ueber alle Vereinbarungen bleiben dem Vollrecht vorbehalten.
            $this->assertFalse($data['sees_totals']);
            $this->assertNull($data['total_amount']);
            $this->assertNull($data['pipeline']);
            $this->assertTrue($data['shows_own_only']);

            $_SESSION['can_manage_sponsoring'] = true;
            $forManager = $this->dashboardData();
            $this->assertTrue($forManager['sees_totals']);
            $this->assertNotNull($forManager['total_amount']);
            $this->assertContains('Fremder Anruf', array_column($forManager['recent_contacts'], 'summary'));
        } finally {
            SponsoringContact::whereIn('id', [$mine->id, $theirs->id])->delete();
            $this->cleanUp($sponsor);
        }
    }

    public function testOnlyTheOwnerMayTickOffAFollowUp(): void
    {
        $this->loginAsContributor();
        $sponsor = $this->makeSponsor();
        $sponsorship = $this->makeSponsorship($sponsor, (int) $this->contributor->id);

        $mine = $this->makeContact($sponsor, $sponsorship, (int) $this->contributor->id, 'Eigene Wiedervorlage');
        $theirs = $this->makeContact($sponsor, $sponsorship, (int) $this->otherUser->id, 'Fremde Wiedervorlage');

        $controller = new SponsoringContactController($this->createStub(Twig::class), new SponsoringPolicy());

        try {
            $denied = $controller->markDone(
                $this->makeRequest('POST', '/x', ['sponsor_id' => (string) $sponsor->id]),
                $this->makeResponse(),
                ['id' => (string) $theirs->id]
            );
            $this->assertSame(403, $denied->getStatusCode());
            $this->assertSame(0, (int) $theirs->fresh()->follow_up_done);

            $allowed = $controller->markDone(
                $this->makeRequest('POST', '/x', ['sponsor_id' => (string) $sponsor->id]),
                $this->makeResponse(),
                ['id' => (string) $mine->id]
            );
            $this->assertSame(302, $allowed->getStatusCode());
            $this->assertSame(1, (int) $mine->fresh()->follow_up_done);
        } finally {
            SponsoringContact::whereIn('id', [$mine->id, $theirs->id])->delete();
            $this->cleanUp($sponsor);
        }
    }

    /**
     * Wer bei der Vereinbarung als zuständig eingetragen ist, hakt deren
     * Wiedervorlagen ebenfalls ab - auch die, die jemand anderer protokolliert
     * hat. Sonst bleibt der Eintrag stehen, sobald die protokollierende Person
     * im Urlaub oder ausgetreten ist, und nur das Sponsoring-Team käme noch
     * heran. Das Ändern des Kontakts bleibt davon unberührt: die
     * Zusammenfassung gehört weiterhin dem, der sie geschrieben hat.
     */
    public function testTheAssignedPersonMayTickOffAFollowUpOnTheirAgreement(): void
    {
        $this->loginAsContributor();
        $sponsor = $this->makeSponsor();

        $sponsorship = $this->makeSponsorship($sponsor, (int) $this->otherUser->id);
        $sponsorship->assigned_user_id = $this->contributor->id;
        $sponsorship->save();

        $theirs = $this->makeContact($sponsor, $sponsorship, (int) $this->otherUser->id, 'Fremde Wiedervorlage');

        $controller = new SponsoringContactController($this->createStub(Twig::class), new SponsoringPolicy());

        try {
            $allowed = $controller->markDone(
                $this->makeRequest('POST', '/x', ['sponsor_id' => (string) $sponsor->id]),
                $this->makeResponse(),
                ['id' => (string) $theirs->id]
            );
            $this->assertSame(302, $allowed->getStatusCode());
            $this->assertSame(1, (int) $theirs->fresh()->follow_up_done);

            // Die Zusammenfassung bleibt trotzdem fremd.
            $this->assertFalse((new SponsoringPolicy())->canEditContact($theirs->fresh()));

            // Und die Detailseite reicht beides getrennt an das Template weiter:
            // ohne das käme der Abhaken-Knopf nie an, weil das Template die
            // Regel zuvor selbst aus Urheber und Vollrecht zusammensetzte.
            $detail = $this->sponsorDetailData((int) $sponsor->id);
            $this->assertTrue($detail['may_complete_follow_ups'][(int) $theirs->id]);
            $this->assertFalse($detail['may_edit_contacts'][(int) $theirs->id]);
        } finally {
            SponsoringContact::whereIn('id', [$theirs->id])->delete();
            $this->cleanUp($sponsor);
        }
    }

    /**
     * Ohne Vereinbarung gibt es keine zuständige Person - dann bleibt es beim
     * Urheber. Sonst hätte die Lockerung eine Lücke für lose Kontakte.
     */
    public function testAFollowUpWithoutAnAgreementStaysWithItsAuthor(): void
    {
        $this->loginAsContributor();
        $sponsor = $this->makeSponsor();

        $theirs = $this->makeContact($sponsor, null, (int) $this->otherUser->id, 'Loser Kontakt');

        $controller = new SponsoringContactController($this->createStub(Twig::class), new SponsoringPolicy());

        try {
            $denied = $controller->markDone(
                $this->makeRequest('POST', '/x', ['sponsor_id' => (string) $sponsor->id]),
                $this->makeResponse(),
                ['id' => (string) $theirs->id]
            );
            $this->assertSame(403, $denied->getStatusCode());
            $this->assertSame(0, (int) $theirs->fresh()->follow_up_done);
        } finally {
            SponsoringContact::whereIn('id', [$theirs->id])->delete();
            $this->cleanUp($sponsor);
        }
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function attachmentOverviewRows(): array
    {
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

        return $captured['attachments'];
    }

    /**
     * @return array<string, mixed>
     */
    private function dashboardData(): array
    {
        $captured = [];
        $twig = $this->createStub(Twig::class);
        $twig->method('render')->willReturnCallback(
            function ($response, string $template, array $data) use (&$captured): ResponseInterface {
                $captured = $data;
                return $response;
            }
        );

        (new SponsoringDashboardController($twig, new NameFormatterService(), new SponsoringPolicy()))
            ->index($this->makeRequest('GET', '/sponsoring'), $this->makeResponse());

        return $captured;
    }

    private function makeContact(
        Sponsor $sponsor,
        ?Sponsorship $sponsorship,
        int $userId,
        string $summary
    ): SponsoringContact {
        return SponsoringContact::create([
            'sponsor_id' => $sponsor->id,
            'sponsorship_id' => $sponsorship?->id,
            'user_id' => $userId,
            'contact_date' => date('Y-m-d'),
            'type' => 'call',
            'summary' => $summary,
            'follow_up_date' => date('Y-m-d', strtotime('+2 days')),
            'follow_up_done' => 0,
        ]);
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

    /**
     * Rendert die Sponsor-Detailseite und gibt zurück, was an das Template geht.
     *
     * @return array<string, mixed>
     */
    private function sponsorDetailData(int $sponsorId): array
    {
        $captured = [];
        $twig = $this->createStub(Twig::class);
        $twig->method('render')->willReturnCallback(
            function ($response, string $template, array $data) use (&$captured): ResponseInterface {
                $captured = $data;
                return $response;
            }
        );

        (new SponsorController($twig, new SponsoringPolicy(), $this->attachmentService()))->detail(
            $this->makeRequest('GET', '/sponsoring/sponsors/' . $sponsorId),
            $this->makeResponse(),
            ['id' => (string) $sponsorId]
        );

        return $captured;
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
