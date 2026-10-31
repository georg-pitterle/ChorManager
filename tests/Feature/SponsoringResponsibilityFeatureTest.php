<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Controllers\SponsorController;
use App\Controllers\SponsoringDashboardController;
use App\Controllers\SponsorshipController;
use App\Models\Role;
use App\Models\Sponsor;
use App\Models\Sponsorship;
use App\Models\User;
use App\Policies\SponsoringPolicy;
use App\Services\NameFormatterService;
use App\Util\PasswordHasher;
use App\Util\SponsorResponsibility;
use App\Util\SponsorshipStatus;
use Illuminate\Database\Capsule\Manager as Capsule;
use Monolog\Handler\TestHandler;
use Monolog\Logger;
use Monolog\LogRecord;
use PHPUnit\Framework\TestCase;
use Slim\Views\Twig;
use Tests\Unit\Bootstrap;

/**
 * Wer kümmert sich um welchen Sponsor?
 *
 * Die Zuständigkeit steht am einzelnen Vorgang, weil Rechte daran hängen. Die
 * Organisation fragt aber nach dem Sponsor - und bekam die Antwort bisher nur,
 * indem sie jeden Sponsor einzeln öffnete. Die Sponsorenliste fasst die
 * Zuständigen deshalb zusammen, das Dashboard nennt die Lücken, und das
 * Vollrecht schließt sie dort in einem Schritt.
 */
final class SponsoringResponsibilityFeatureTest extends TestCase
{
    use TestHttpHelpers;
    use TwigViewStubs;

    private User $manager;
    private User $anna;
    private User $bernd;

    protected function setUp(): void
    {
        parent::setUp();
        Bootstrap::setupTestDatabase();
        Capsule::connection()->beginTransaction();

        $this->manager = $this->person('Mara', 'Vollrecht');
        $this->anna = $this->person('Anna', 'Aigner');
        $this->bernd = $this->person('Bernd', 'Berger');
        $role = Role::create([
            'name' => 'Sponsoring ' . bin2hex(random_bytes(4)),
            'hierarchy_level' => 10,
            'can_create_own_sponsorships' => 1,
        ]);
        $this->anna->roles()->attach($role->id);
        $this->bernd->roles()->attach($role->id);
        $this->loginAs(true);
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

    public function testTheSponsorSummaryNamesEachPersonOfTheOngoingCasesOnce(): void
    {
        $sponsor = Sponsor::create(['name' => 'Mehrfach GmbH']);
        $this->case($sponsor, SponsorshipStatus::ACCEPTED, $this->bernd);
        $this->case($sponsor, SponsorshipStatus::REQUESTED, $this->anna);
        $this->case($sponsor, SponsorshipStatus::REMINDED, $this->anna);

        $summary = SponsorResponsibility::forSponsor($sponsor->fresh());

        $this->assertSame(
            [(int) $this->anna->id, (int) $this->bernd->id],
            array_map(static fn (User $user): int => (int) $user->id, $summary['assignees'])
        );
        $this->assertSame(0, $summary['unassigned']);
    }

    public function testFinishedCasesDoNotCount(): void
    {
        // Wer vor zwei Jahren eine Absage eingeholt hat, ist heute nicht mehr
        // zuständig - und eine fehlende Person daran ist keine Lücke.
        $sponsor = Sponsor::create(['name' => 'Vergangen KG']);
        $this->case($sponsor, SponsorshipStatus::DECLINED, $this->anna);
        $this->case($sponsor, SponsorshipStatus::CLOSED, null);

        $summary = SponsorResponsibility::forSponsor($sponsor->fresh());

        $this->assertSame([], $summary['assignees']);
        $this->assertSame(0, $summary['unassigned']);
    }

    public function testACaseWithoutAPersonIsAGap(): void
    {
        $sponsor = Sponsor::create(['name' => 'Lücke OG']);
        $this->case($sponsor, SponsorshipStatus::ACCEPTED, $this->anna);
        $this->case($sponsor, SponsorshipStatus::REQUESTED, null);

        $summary = SponsorResponsibility::forSponsor($sponsor->fresh());

        $this->assertCount(1, $summary['assignees']);
        $this->assertSame(1, $summary['unassigned']);
    }

    public function testAnArchivedPersonCountsAsAGap(): void
    {
        // Die Person darf eingetragen bleiben, damit ein Speichern aus anderem
        // Grund nicht scheitert. Kümmern kann sie sich trotzdem nicht mehr.
        $sponsor = Sponsor::create(['name' => 'Ausgetreten AG']);
        $this->case($sponsor, SponsorshipStatus::ACCEPTED, $this->archivedPerson());

        $summary = SponsorResponsibility::forSponsor($sponsor->fresh());

        $this->assertSame([], $summary['assignees']);
        $this->assertSame(1, $summary['unassigned']);
    }

    public function testTheDashboardListsOngoingCasesWithoutAPersonAcceptedFirst(): void
    {
        $this->case(Sponsor::create(['name' => 'A Anfrage']), SponsorshipStatus::REQUESTED, null);
        $this->case(Sponsor::create(['name' => 'B Erinnert']), SponsorshipStatus::REMINDED, null);
        $this->case(Sponsor::create(['name' => 'Z Zusage']), SponsorshipStatus::ACCEPTED, null);
        $this->case(Sponsor::create(['name' => 'Versorgt']), SponsorshipStatus::ACCEPTED, $this->anna);
        $this->case(Sponsor::create(['name' => 'Fertig']), SponsorshipStatus::CLOSED, null);
        $this->case(Sponsor::create(['name' => 'Abgesagt']), SponsorshipStatus::DECLINED, null);

        $this->assertSame(
            ['Z Zusage', 'A Anfrage', 'B Erinnert'],
            array_column($this->unassigned(), 'sponsor_name')
        );
    }

    public function testTheDashboardNamesTheArchivedPersonSoTheGapExplainsItself(): void
    {
        $former = $this->archivedPerson();
        $this->case(Sponsor::create(['name' => 'Verwaist']), SponsorshipStatus::ACCEPTED, $former);

        $rows = $this->unassigned();

        $this->assertCount(1, $rows);
        $this->assertSame('Ehemals Dabei', $rows[0]['former_assignee_name']);
    }

    public function testManagersGetTheGapPanelWithAnAssignForm(): void
    {
        $case = $this->case(Sponsor::create(['name' => 'Ohne Person']), SponsorshipStatus::ACCEPTED, null);

        $html = $this->renderDashboard();

        $this->assertStringContainsString('Ohne Zuständigkeit', $html);
        $this->assertStringContainsString('action="/sponsoring/sponsorships/' . $case->id . '/assignee"', $html);
        $this->assertStringContainsString('Anna Aigner', $html);
    }

    public function testContributorsDoNotGetTheGapPanel(): void
    {
        $this->case(Sponsor::create(['name' => 'Ohne Person']), SponsorshipStatus::ACCEPTED, null);
        $this->loginAs(false);

        $html = $this->renderDashboard();

        $this->assertStringNotContainsString('Ohne Zuständigkeit', $html);
        $this->assertStringNotContainsString('/assignee"', $html);
    }

    public function testTheWorkListsNameTheResponsiblePerson(): void
    {
        $case = $this->case(Sponsor::create(['name' => 'Arbeitsliste']), SponsorshipStatus::ACCEPTED, $this->bernd);
        $case->update(['amount' => '500.00']);
        $case->benefits()->create(['description' => 'Logo im Programmheft', 'sort_order' => 0]);
        $case->deliverables()->create(['description' => 'Banner', 'sort_order' => 0]);

        $html = $this->renderDashboard();

        // Offene Gegenleistungen, offene Leistungen des Sponsors, Zahlung offen.
        $this->assertSame(3, substr_count($html, 'Zuständig: Bernd Berger'));
    }

    public function testTheDashboardOffersTheAssignedPersonOnlyDeliverablesToTick(): void
    {
        $case = $this->case(Sponsor::create(['name' => 'Haken']), SponsorshipStatus::ACCEPTED, $this->manager);
        $case->benefits()->create(['description' => 'Logo im Programmheft', 'sort_order' => 0]);
        $case->deliverables()->create(['description' => 'Logo als Datei', 'sort_order' => 0]);
        $this->loginAs(false);

        $html = $this->renderDashboard();

        $this->assertStringContainsString('action="/sponsoring/sponsorship-deliverables/', $html);
        $this->assertStringNotContainsString('action="/sponsoring/sponsorship-benefits/', $html);
    }

    public function testAManagerAssignsAPersonInOneStep(): void
    {
        $case = $this->case(Sponsor::create(['name' => 'Zuweisen']), SponsorshipStatus::ACCEPTED, null);
        $handler = new TestHandler();

        $response = $this->assign($case, (string) $this->anna->id, $handler);

        $this->assertSame(302, $response->getStatusCode());
        $this->assertSame('/sponsoring', $response->getHeaderLine('Location'));
        $this->assertSame((int) $this->anna->id, (int) $case->fresh()->assigned_user_id);
        $this->assertSame('Anna Aigner ist jetzt für Zuweisen zuständig.', $_SESSION['success']);
        $this->assertTrue($handler->hasInfoThatPasses(
            static fn (LogRecord $record): bool => ($record->context['event'] ?? '') === 'sponsorship.assignee.changed'
        ));
    }

    public function testAssigningReplacesAnArchivedPerson(): void
    {
        $case = $this->case(Sponsor::create(['name' => 'Nachfolge']), SponsorshipStatus::ACCEPTED, $this->archivedPerson());

        $this->assign($case, (string) $this->anna->id);

        $this->assertSame((int) $this->anna->id, (int) $case->fresh()->assigned_user_id);
        $this->assertSame([], $this->unassigned());
    }

    public function testAContributorMayNotAssignEvenOnTheirOwnCase(): void
    {
        $this->loginAs(false);
        $case = $this->case(Sponsor::create(['name' => 'Eigener Vorgang']), SponsorshipStatus::REQUESTED, null);
        $case->update(['created_by_user_id' => $this->manager->id]);

        $response = $this->assign($case, (string) $this->anna->id);

        $this->assertSame(403, $response->getStatusCode());
        $this->assertNull($case->fresh()->assigned_user_id);
    }

    public function testNoSelectionIsRefusedWithAHint(): void
    {
        $case = $this->case(Sponsor::create(['name' => 'Leer']), SponsorshipStatus::ACCEPTED, $this->bernd);

        $this->assign($case, '');

        $this->assertSame('Bitte eine Person auswählen.', $_SESSION['error']);
        $this->assertSame((int) $this->bernd->id, (int) $case->fresh()->assigned_user_id);
    }

    public function testAnArchivedOrUnknownPersonIsRefused(): void
    {
        $case = $this->case(Sponsor::create(['name' => 'Ungültig']), SponsorshipStatus::ACCEPTED, null);

        foreach ([(string) $this->archivedPerson()->id, '999999999'] as $userId) {
            unset($_SESSION['error']);
            $this->assign($case, $userId);

            $this->assertSame(SponsorshipController::ASSIGNEE_ERROR, $_SESSION['error']);
            $this->assertNull($case->fresh()->assigned_user_id);
        }
    }

    public function testAPersonWithoutSponsoringRightIsNeitherOfferedNorAccepted(): void
    {
        // Sie sähe den Vorgang nie: /sponsoring antwortet ihr mit 403. Die Lücke
        // verschwände aus der Liste, ohne dass sich jemand kümmert.
        $outsider = $this->person('Olga', 'Ohnerecht');
        $case = $this->case(Sponsor::create(['name' => 'Ohne Recht']), SponsorshipStatus::ACCEPTED, null);

        $this->assertStringNotContainsString('Olga Ohnerecht', $this->renderDashboard());

        $this->assign($case, (string) $outsider->id);

        $this->assertSame(SponsorshipController::ASSIGNEE_NO_ACCESS_ERROR, $_SESSION['error']);
        $this->assertNull($case->fresh()->assigned_user_id);
    }

    public function testAStaleTabDoesNotOverwriteSomeoneAlreadyAssigned(): void
    {
        // Die Zuständigkeit gibt Einsicht in Betrag und Belege - sie soll nicht
        // still wandern, weil jemand eine alte Seite offen hatte.
        $case = $this->case(Sponsor::create(['name' => 'Alter Tab']), SponsorshipStatus::ACCEPTED, $this->bernd);

        $this->assign($case, (string) $this->anna->id);

        $this->assertSame(SponsorshipController::ASSIGNEE_NOT_OPEN_ERROR, $_SESSION['error']);
        $this->assertSame((int) $this->bernd->id, (int) $case->fresh()->assigned_user_id);
    }

    public function testAFinishedCaseIsNotAssigned(): void
    {
        $case = $this->case(Sponsor::create(['name' => 'Schon fertig']), SponsorshipStatus::CLOSED, null);

        $this->assign($case, (string) $this->anna->id);

        $this->assertSame(SponsorshipController::ASSIGNEE_NOT_OPEN_ERROR, $_SESSION['error']);
        $this->assertNull($case->fresh()->assigned_user_id);
    }

    public function testTheWorkListsTreatAnArchivedPersonAsNobody(): void
    {
        // Beitragende sehen "Ohne Zuständigkeit" nicht. Stünde hier der Name,
        // hielten sie den Vorgang für versorgt.
        $sponsor = Sponsor::create(['name' => 'Verwaiste Liste']);
        $case = $this->case($sponsor, SponsorshipStatus::ACCEPTED, $this->archivedPerson());
        $case->update(['amount' => '500.00', 'needs_logo_print' => true]);
        $case->benefits()->create(['description' => 'Freikarten', 'sort_order' => 0]);
        $this->loginAs(false);

        $html = $this->renderDashboard();

        $this->assertStringNotContainsString('Ehemals Dabei', $html);
        $this->assertStringContainsString('Zuständig: niemand', $html);
    }

    public function testTheEditDialogKeepsAnArchivedPerson(): void
    {
        // Ohne eigene Option schickte der Browser "Niemand": ein korrigierter
        // Betrag hätte die Zuständigkeit still gelöscht.
        $sponsor = Sponsor::create(['name' => 'Dialogfirma']);
        $former = $this->archivedPerson();
        $this->case($sponsor, SponsorshipStatus::ACCEPTED, $former);

        $html = $this->renderSponsorPage($sponsor);

        $this->assertMatchesRegularExpression(
            '~<option value="' . $former->id . '"\s+selected>\s*Ehemals Dabei \(nicht mehr aktiv\)~u',
            $html
        );
    }

    public function testAnUnknownCaseEndsInAMessageNotAnError(): void
    {
        $response = $this->sponsorshipController()->assign(
            $this->makeRequest('POST', '/sponsoring/sponsorships/999999999/assignee', ['assigned_user_id' => (string) $this->anna->id]),
            $this->makeResponse(),
            ['id' => '999999999']
        );

        $this->assertSame('/sponsoring', $response->getHeaderLine('Location'));
        $this->assertSame('Vorgang nicht gefunden.', $_SESSION['error']);
    }

    public function testTheSponsorListShowsWhoIsResponsibleAndOffersTheFilter(): void
    {
        $sponsor = Sponsor::create(['name' => 'Listenfirma']);
        $this->case($sponsor, SponsorshipStatus::ACCEPTED, $this->anna);
        $this->case($sponsor, SponsorshipStatus::REQUESTED, null);
        $this->case(Sponsor::create(['name' => 'Nur Lücke']), SponsorshipStatus::REQUESTED, null);

        $html = $this->renderSponsorList();

        $this->assertStringContainsString('data-label="Zuständig"', $html);
        $this->assertStringContainsString('Anna Aigner', $html);
        $this->assertStringContainsString('1 Vorgang ohne', $html);
        $this->assertStringContainsString('data-assignees="' . $this->anna->id . ' none"', $html);
        $this->assertStringContainsString('data-assignees="none"', $html);
        $this->assertStringContainsString('data-table-plugins="sponsorState,sponsorCategory,sponsorAssignee"', $html);
        $this->assertStringContainsString('/js/table-plugins/sponsors-assignee-plugin.js', $html);

        // Der Filter bietet nur an, wer in der Liste auch vorkommt.
        $this->assertStringContainsString('Niemand', html_entity_decode($html));
        $this->assertStringNotContainsString('Bernd Berger', html_entity_decode($html));
    }

    public function testASponsorWithoutOngoingCasesHasNoEntryAndNoWarning(): void
    {
        $this->case(Sponsor::create(['name' => 'Alte Sache']), SponsorshipStatus::CLOSED, $this->anna);

        $html = $this->renderSponsorList();

        $this->assertStringContainsString('data-assignees=""', $html);
        $this->assertStringNotContainsString('text-bg-warning">niemand', $html);
    }

    public function testTheSponsorPageSaysWhoIsResponsible(): void
    {
        $sponsor = Sponsor::create(['name' => 'Steckbrieffirma']);
        $this->case($sponsor, SponsorshipStatus::ACCEPTED, $this->bernd);

        $html = $this->renderSponsorPage($sponsor);

        $this->assertMatchesRegularExpression('~<dt>Zuständig</dt>\s*<dd>\s*Bernd Berger~u', $html);
    }

    /**
     * Beide Personenauswahlen stehen in der eingestellten Namensreihenfolge.
     *
     * Sie sortierten hart nach `last_name`, während der Filter `person_name`
     * daneben "Vorname Nachname" ausgab - die Auswahl las sich dadurch
     * unsortiert, und wer eine Person suchte, musste die ganze Liste durchgehen.
     *
     * Die beiden Namen kreuzen sich absichtlich: Nach Vornamen kommt Anton
     * Zehner zuerst, nach Nachnamen Zita Albrecht. Ohne diese Kreuzung wäre der
     * Test auch mit der alten Sortierung grün.
     *
     * @return array{0: User, 1: User}
     */
    private function crossingPair(): array
    {
        $role = Role::create([
            'name' => 'Sponsoring Reihenfolge ' . bin2hex(random_bytes(4)),
            'hierarchy_level' => 10,
            'can_create_own_sponsorships' => 1,
        ]);

        $zehner = $this->person('Anton', 'Zehner');
        $albrecht = $this->person('Zita', 'Albrecht');
        $zehner->roles()->attach($role->id);
        $albrecht->roles()->attach($role->id);

        return [$zehner, $albrecht];
    }

    public function testTheAssignSelectOnTheDashboardFollowsTheConfiguredNameOrder(): void
    {
        $this->crossingPair();
        $this->case(Sponsor::create(['name' => 'Offene Sache']), SponsorshipStatus::ACCEPTED, null);

        $html = $this->renderDashboard();

        $this->assertStringContainsString('Anton Zehner', $html);
        $this->assertLessThan(
            strpos($html, 'Zita Albrecht'),
            strpos($html, 'Anton Zehner'),
            'Die Auswahl unter "Ohne Zuständigkeit" muss der eingestellten '
                . 'Namensreihenfolge folgen, nicht dem Nachnamen.'
        );
    }

    public function testTheAssigneeSelectOnTheSponsorPageFollowsTheConfiguredNameOrder(): void
    {
        $this->crossingPair();
        $sponsor = Sponsor::create(['name' => 'Reihenfolgefirma']);
        $this->case($sponsor, SponsorshipStatus::ACCEPTED, $this->bernd);

        $html = $this->renderSponsorPage($sponsor);

        $this->assertStringContainsString('Anton Zehner', $html);
        $this->assertLessThan(
            strpos($html, 'Zita Albrecht'),
            strpos($html, 'Anton Zehner'),
            'Die Auswahl "Zuständig" im Bearbeiten-Dialog muss der eingestellten '
                . 'Namensreihenfolge folgen, nicht dem Nachnamen.'
        );
    }

    /**
     * Beide Personenauswahlen laden nur die Listenspalten.
     *
     * Sie reichen ihre User-Modelle unverändert an die Vorlage weiter und
     * fallen damit unter dieselbe Regel wie die Abfragen in src/Queries
     * (UserColumnExposureFeatureTest): Der Passwort-Hash hat in einer Abfrage
     * nichts zu suchen, deren Ergebnis in der View landet. Beide holten zuvor
     * die ganze Zeile.
     */
    public function testBothPersonSelectsLoadOnlyTheListColumns(): void
    {
        $this->crossingPair();

        $sponsor = Sponsor::create(['name' => 'Spaltenfirma']);
        $this->case($sponsor, SponsorshipStatus::ACCEPTED, null);

        $selects = [
            'SponsorController::selectableUsers' => (new \ReflectionMethod(
                SponsorController::class,
                'selectableUsers'
            ))->invoke(new SponsorController(
                $this->createStub(Twig::class),
                new SponsoringPolicy($_SESSION),
                $this->attachmentService()
            )),
            'SponsoringDashboardController::assignableUsers' => (new \ReflectionMethod(
                SponsoringDashboardController::class,
                'assignableUsers'
            ))->invoke(new SponsoringDashboardController(
                $this->createStub(Twig::class),
                new NameFormatterService(),
                new SponsoringPolicy($_SESSION)
            )),
        ];

        foreach ($selects as $label => $users) {
            $this->assertGreaterThan(0, $users->count(), $label . ' bietet niemanden an.');

            foreach ($users as $user) {
                $this->assertArrayNotHasKey(
                    'password',
                    $user->getAttributes(),
                    $label . ' lädt den Passwort-Hash in die View-Schicht.'
                );
                foreach (['id', 'first_name', 'last_name'] as $column) {
                    $this->assertArrayHasKey(
                        $column,
                        $user->getAttributes(),
                        $label . ' lädt die von der Auswahl benötigte Spalte ' . $column . ' nicht.'
                    );
                }
            }
        }
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function unassigned(): array
    {
        $method = new \ReflectionMethod(SponsoringDashboardController::class, 'unassignedSponsorships');

        return $method->invoke(new SponsoringDashboardController(
            $this->createStub(Twig::class),
            new NameFormatterService(),
            new SponsoringPolicy($_SESSION)
        ));
    }

    private function renderDashboard(): string
    {
        $twig = $this->createAppTwig('/sponsoring');
        $response = (new SponsoringDashboardController($twig, new NameFormatterService(), new SponsoringPolicy($_SESSION)))
            ->index($this->makeRequest('GET', '/sponsoring'), $this->makeResponse());

        return (string) $response->getBody();
    }

    private function renderSponsorPage(Sponsor $sponsor): string
    {
        return (string) (new SponsorController(
            $this->createAppTwig('/sponsoring/sponsors'),
            new SponsoringPolicy($_SESSION),
            $this->attachmentService()
        ))->detail(
            $this->makeRequest('GET', '/sponsoring/sponsors/' . $sponsor->id),
            $this->makeResponse(),
            ['id' => (string) $sponsor->id]
        )->getBody();
    }

    private function renderSponsorList(): string
    {
        $response = (new SponsorController(
            $this->createAppTwig('/sponsoring/sponsors'),
            new SponsoringPolicy($_SESSION),
            $this->attachmentService()
        ))->index($this->makeRequest('GET', '/sponsoring/sponsors'), $this->makeResponse());

        return (string) $response->getBody();
    }

    private function assign(Sponsorship $case, string $userId, ?TestHandler $handler = null): \Psr\Http\Message\ResponseInterface
    {
        return $this->sponsorshipController($handler)->assign(
            $this->makeRequest('POST', '/sponsoring/sponsorships/' . $case->id . '/assignee', ['assigned_user_id' => $userId]),
            $this->makeResponse(),
            ['id' => (string) $case->id]
        );
    }

    private function sponsorshipController(?TestHandler $handler = null): SponsorshipController
    {
        $logger = new Logger('test');
        if ($handler !== null) {
            $logger->pushHandler($handler);
        }

        return new SponsorshipController(new SponsoringPolicy($_SESSION), $this->attachmentService(), $logger);
    }

    private function case(Sponsor $sponsor, string $status, ?User $assignee): Sponsorship
    {
        return Sponsorship::create([
            'sponsor_id' => $sponsor->id,
            'status' => $status,
            'amount' => '0.00',
            'assigned_user_id' => $assignee?->id,
        ]);
    }

    private function loginAs(bool $manager): void
    {
        $_SESSION = [
            'user_id' => $this->manager->id,
            'can_manage_sponsoring' => $manager,
            'can_create_own_sponsorships' => !$manager,
        ];
    }

    private function person(string $firstName, string $lastName): User
    {
        return User::create([
            'first_name' => $firstName,
            'last_name' => $lastName,
            'email' => 'zustaendig-' . bin2hex(random_bytes(6)) . '@example.test', // naming:ascii
            'password' => PasswordHasher::hash('x'),
            'is_active' => 1,
        ]);
    }

    private function archivedPerson(): User
    {
        $person = $this->person('Ehemals', 'Dabei');
        $person->update(['is_active' => 0]);

        return $person;
    }
}
