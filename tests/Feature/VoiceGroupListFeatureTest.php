<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Controllers\VoiceGroupListController;
use App\Middleware\RoleMiddleware;
use App\Models\User;
use App\Models\VoiceGroup;
use App\Models\VoiceGroupList;
use App\Models\VoiceGroupListColumn;
use App\Models\VoiceGroupListEntry;
use App\Policies\VoiceGroupListPolicy;
use App\Services\NameFormatterService;
use App\Services\VoiceGroupListRows;
use Illuminate\Database\Capsule\Manager as Capsule;
use Monolog\Handler\TestHandler;
use PHPUnit\Framework\TestCase;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\RequestHandlerInterface;
use Slim\Psr7\Response;
use Tests\Unit\Bootstrap;

/**
 * Stimmgruppen-Listen anlegen, ansehen und pflegen.
 *
 * Schwerpunkt ist die Abschottung: Eine Liste einer fremden Stimmgruppe
 * antwortet genau wie eine, die es nicht gibt - sonst ließe sich durch
 * Hochzählen der Id erraten, welche Listen es gibt.
 */
final class VoiceGroupListFeatureTest extends TestCase
{
    use TestHttpHelpers;
    use TwigViewStubs;
    use VoiceGroupListFixtures;

    private VoiceGroup $group;
    private VoiceGroup $foreignGroup;
    private User $representative;
    private TestHandler $logs;

    protected function setUp(): void
    {
        parent::setUp();
        Bootstrap::setupTestDatabase();
        Capsule::connection()->beginTransaction();

        $this->group = $this->makeVoiceGroup('Sopran');
        $this->foreignGroup = $this->makeVoiceGroup('Bass');
        $this->representative = $this->makeMember($this->group, null, true, 'Vertretung');
        $_SESSION = $this->representativeSession($this->representative, $this->group);
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

    public function testARepresentativeCreatesAListForTheOwnVoiceGroup(): void
    {
        $response = $this->controller()->create(
            $this->makeRequest('POST', '/voice-group-lists', ['title' => 'Notenausgabe Geheim']),
            $this->makeResponse()
        );

        $list = VoiceGroupList::where('title', 'Notenausgabe Geheim')->firstOrFail();
        $this->assertRedirect($response, '/voice-group-lists/' . $list->id);
        $this->assertSame((int) $this->group->id, (int) $list->voice_group_id);
        $this->assertSame((int) $this->representative->id, (int) $list->created_by_user_id);
        $this->assertNull($list->project_id);

        $record = $this->recordFor($this->logs, 'voice_group_list.created');
        $this->assertNotNull($record);
        $this->assertSame((int) $list->id, $record->context['list_id']);
        $this->assertStringNotContainsString('Notenausgabe Geheim', json_encode($record->toArray()) ?: '');
    }

    public function testAnEmptyTitleIsRejected(): void
    {
        $this->controller()->create($this->makeRequest('POST', '/voice-group-lists', ['title' => '  ']), $this->makeResponse());

        $this->assertSame('Bitte einen Titel eingeben.', $_SESSION['error'] ?? null);
        $this->assertSame(0, VoiceGroupList::where('voice_group_id', $this->group->id)->count());
    }

    public function testAListForAForeignVoiceGroupIsRejected(): void
    {
        $this->controller()->create($this->makeRequest('POST', '/voice-group-lists', [
            'title' => 'Fremdliste',
            'voice_group_id' => (string) $this->foreignGroup->id,
        ]), $this->makeResponse());

        $this->assertSame('Bitte eine eigene Stimmgruppe wählen.', $_SESSION['error'] ?? null);
        $this->assertSame(0, VoiceGroupList::where('title', 'Fremdliste')->count());
    }

    public function testAProjectWithoutMembersOfTheVoiceGroupIsRejected(): void
    {
        $foreignProject = $this->makeProject([$this->makeMember($this->foreignGroup)]);

        $this->controller()->create($this->makeRequest('POST', '/voice-group-lists', [
            'title' => 'Projektliste',
            'project_id' => (string) $foreignProject->id,
        ]), $this->makeResponse());
        $this->assertSame('Dieses Projekt passt nicht zur Stimmgruppe.', $_SESSION['error'] ?? null);
        $this->assertSame(0, VoiceGroupList::where('title', 'Projektliste')->count());

        $list = $this->makeList($this->group);
        unset($_SESSION['error']);
        $this->controller()->update($this->makeRequest('POST', '/voice-group-lists/' . $list->id, [
            'title' => 'Umbenannt',
            'project_id' => (string) $foreignProject->id,
        ]), $this->makeResponse(), ['id' => (string) $list->id]);
        $this->assertSame('Dieses Projekt passt nicht zur Stimmgruppe.', $_SESSION['error'] ?? null);
        $this->assertSame('Sommerkonzert', $list->fresh()?->title);
        $this->assertNull($list->fresh()?->project_id);
    }

    public function testRenamingKeepsAProjectTheVoiceGroupHasLeft(): void
    {
        $leaver = $this->makeMember($this->group);
        $project = $this->makeProject([$leaver]);
        $list = $this->makeList($this->group, $project);
        $project->users()->detach($leaver->id);

        $html = $this->showHtml($list);
        $this->assertMatchesRegularExpression('/<option value="' . $project->id . '"\s+selected>/', $html);

        $this->controller()->update($this->makeRequest('POST', '/voice-group-lists/' . $list->id, [
            'title' => 'Umbenannt',
            'project_id' => (string) $project->id,
        ]), $this->makeResponse(), ['id' => (string) $list->id]);

        $this->assertNull($_SESSION['error'] ?? null);
        $this->assertSame('Umbenannt', $list->fresh()?->title);
        $this->assertSame((int) $project->id, (int) $list->fresh()?->project_id);
    }

    public function testTheRunningProjectIsPreselectedForANewList(): void
    {
        $past = $this->makeProject([$this->representative]);
        $past->update(['start_date' => '2020-01-01', 'end_date' => '2020-06-30']);
        $running = $this->makeProject([$this->representative]);
        $running->update(['start_date' => date('Y-m-d', strtotime('-10 days')), 'end_date' => date('Y-m-d', strtotime('+30 days'))]);

        $html = (string) $this->controller()->index($this->makeRequest('GET', '/voice-group-lists'), $this->makeResponse())
            ->getBody();

        $this->assertMatchesRegularExpression('/<option value="' . $running->id . '"\s+selected>/', $html);
        $this->assertDoesNotMatchRegularExpression('/<option value="' . $past->id . '"\s+selected>/', $html);
    }

    public function testWithoutARunningProjectNothingIsPreselected(): void
    {
        $past = $this->makeProject([$this->representative]);
        $past->update(['start_date' => '2020-01-01', 'end_date' => '2020-06-30']);

        $html = (string) $this->controller()->index($this->makeRequest('GET', '/voice-group-lists'), $this->makeResponse())
            ->getBody();

        $this->assertDoesNotMatchRegularExpression('/<option value="\d+"\s+selected>/', $html);
    }

    public function testTheListPageShowsItsProject(): void
    {
        $project = $this->makeProject([$this->representative]);
        $withProject = $this->makeList($this->group, $project, 'Mit Projekt');
        $withoutProject = $this->makeList($this->group, null, 'Ohne');

        $this->assertStringContainsString('Projekt: <strong>' . $project->name . '</strong>', $this->showHtml($withProject));
        $this->assertStringContainsString('Ohne Projekt – alle Mitglieder der Stimmgruppe', $this->showHtml($withoutProject));
    }

    public function testTheOverviewNamesListsWithoutProjectAndShowsProgress(): void
    {
        $other = $this->makeMember($this->group);
        $project = $this->makeProject([$this->representative, $other]);
        $projectList = $this->makeList($this->group, $project, (string) $project->name);
        $column = $this->makeColumn($projectList);
        $this->tick($column, $this->representative);
        $this->makeList($this->group, null, 'Notenausgabe');

        $html = (string) $this->controller()->index($this->makeRequest('GET', '/voice-group-lists'), $this->makeResponse())
            ->getBody();

        // Ohne Projekt stand rechts ein leerer Text: Twig nahm bei fehlendem Projekt
        // die Methode project() statt des Werts, und die ist immer wahr.
        $this->assertStringContainsString('Ohne Projekt', $html);
        $this->assertStringContainsString('Beitrag bezahlt 1 / 2', $html);
        // Heißt die Liste wie ihr Projekt, steht der Name nicht zweimal da.
        $this->assertSame(1, substr_count($html, '>' . $project->name . '<'));
        $this->assertStringContainsString('data-bs-target="#newListModal"', $html);
    }

    public function testTheHeaderCountsAllMembersAlsoWhileFiltering(): void
    {
        $this->makeMember($this->group, null, true, 'Offenmitglied');
        $list = $this->makeList($this->group);
        $column = $this->makeColumn($list);
        $this->tick($column, $this->representative);

        $html = $this->showHtml($list, ['open' => (string) $column->id]);

        $this->assertStringContainsString('2 Mitglieder', $html);
        $this->assertStringContainsString('1 von 2 offen', $html);
        $this->assertStringContainsString('Alle zeigen', $html);
    }

    public function testTheProgressStripShowsEveryTickColumn(): void
    {
        $this->makeMember($this->group);
        $list = $this->makeList($this->group);
        $paid = $this->makeColumn($list);
        $this->makeColumn($list, VoiceGroupListColumn::TYPE_NOTE, 'Kleidergröße', 1);
        $this->tick($paid, $this->representative);

        $html = $this->showHtml($list);

        $this->assertStringContainsString('--progress-value: 50%', $html);
        $this->assertStringContainsString('href="/voice-group-lists/' . $list->id . '?open=' . $paid->id . '"', $html);
        $this->assertStringContainsString('Nur offene zeigen', $html);
        // Im Tabellenkopf steht kein verkleinerter Text mehr (10,5px).
        $this->assertDoesNotMatchRegularExpression('/<th[^>]*>(?:(?!<\/th>).)*class="small/s', $html);
    }

    public function testACompleteColumnSaysSo(): void
    {
        $list = $this->makeList($this->group);
        $column = $this->makeColumn($list);
        $this->tick($column, $this->representative);

        $this->assertStringContainsString('Alle erledigt', $this->showHtml($list));
    }

    public function testListActionsSitInAManageMenu(): void
    {
        $html = $this->showHtml($this->makeList($this->group));

        $this->assertStringContainsString('Liste verwalten', $html);
        $this->assertMatchesRegularExpression('/class="dropdown-item text-danger"[^>]*>\s*<i[^>]*><\/i>\s*Liste löschen/', $html);
    }

    public function testColumnOrderControlsSayWhatTheyDo(): void
    {
        $list = $this->makeList($this->group);
        $this->makeColumn($list, VoiceGroupListColumn::TYPE_CHECKBOX, 'Beitrag bezahlt', 0);
        $this->makeColumn($list, VoiceGroupListColumn::TYPE_NOTE, 'Kleidergröße', 1);

        $html = $this->showHtml($list);

        // Zwei Pfeile ohne Text ließen offen, was sie tun - und "links/rechts" stimmt
        // in der Kartenansicht nicht, dort stehen die Spalten untereinander.
        $this->assertStringContainsString('Reihenfolge: Position 1 von 2', $html);
        $this->assertStringContainsString('Reihenfolge: Position 2 von 2', $html);
        $this->assertMatchesRegularExpression('/<\/i>\s*Nach hinten\s*<\/button>/', $html);
        $this->assertMatchesRegularExpression('/<\/i>\s*Nach vorne\s*<\/button>/', $html);
        $this->assertStringNotContainsString('aria-label="Nach links"', $html);
    }

    public function testColumnEditTogglesLookLikeButtons(): void
    {
        $list = $this->makeList($this->group);
        $this->makeColumn($list);

        $html = $this->showHtml($list);

        // Ein grauer Text "Bearbeiten" war nicht als Bedienelement zu erkennen.
        $this->assertMatchesRegularExpression(
            '/<summary[^>]*voice-group-list-column-tools__summary[^>]*>.*?Beitrag bezahlt.*?'
                . 'class="btn btn-sm btn-outline-secondary[^"]*"[^>]*>\s*'
                . '<span[^>]*when-closed[^>]*>\s*<i class="bi bi-pencil"[^>]*><\/i>\s*Bearbeiten/s',
            $html
        );
        $this->assertMatchesRegularExpression('/when-open[^>]*>\s*<i class="bi bi-x-lg"[^>]*><\/i>\s*Schließen/', $html);
        $this->assertStringContainsString('voice-group-list-columns__chevron', $html);
    }

    public function testTheListPageLeadsBackWithAButton(): void
    {
        $html = $this->showHtml($this->makeList($this->group));

        // Zurück wie auf den übrigen Detailseiten (Termin, Sponsor): ein Knopf, keine Brotkrumen.
        $this->assertStringNotContainsString('class="breadcrumb', $html);
        $this->assertMatchesRegularExpression(
            '/<a href="\/voice-group-lists"\s+class="btn btn-outline-secondary">\s*<i class="bi bi-arrow-left[^"]*"[^>]*><\/i>\s*Alle Listen/',
            $html
        );
    }

    public function testAProjectWithMembersOfTheVoiceGroupIsAccepted(): void
    {
        $project = $this->makeProject([$this->representative]);

        $this->controller()->create($this->makeRequest('POST', '/voice-group-lists', [
            'title' => 'Mit Projekt',
            'project_id' => (string) $project->id,
        ]), $this->makeResponse());

        $this->assertSame((int) $project->id, (int) VoiceGroupList::where('title', 'Mit Projekt')->value('project_id'));
    }

    public function testTheOverviewShowsOnlyListsOfOwnVoiceGroups(): void
    {
        $this->makeList($this->group, null, 'Eigene Liste');
        $archived = $this->makeList($this->group, null, 'Alte Liste');
        $archived->update(['archived_at' => '2026-10-01 10:00:00']);
        $this->makeList($this->foreignGroup, null, 'Fremde Liste');

        $html = (string) $this->controller()->index($this->makeRequest('GET', '/voice-group-lists'), $this->makeResponse())
            ->getBody();

        $this->assertStringContainsString('Eigene Liste', $html);
        $this->assertStringContainsString('Archiviert', $html);
        $this->assertStringContainsString('Alte Liste', $html);
        $this->assertStringNotContainsString('Fremde Liste', $html);
        $this->assertGreaterThan(strpos($html, 'Eigene Liste'), strpos($html, 'Alte Liste'));
    }

    public function testAForeignListAnswersLikeAMissingOne(): void
    {
        $foreign = $this->makeList($this->foreignGroup, null, 'Fremde Liste');
        $controller = $this->controller();

        foreach ([(string) $foreign->id, '999999'] as $id) {
            $responses = [
                $controller->show($this->makeRequest('GET', '/voice-group-lists/' . $id), $this->makeResponse(), ['id' => $id]),
                $controller->update(
                    $this->makeRequest('POST', '/voice-group-lists/' . $id, ['title' => 'Übernommen']),
                    $this->makeResponse(),
                    ['id' => $id]
                ),
                $controller->archive($this->makeRequest('POST', '/x'), $this->makeResponse(), ['id' => $id]),
                $controller->restore($this->makeRequest('POST', '/x'), $this->makeResponse(), ['id' => $id]),
                $controller->delete($this->makeRequest('POST', '/x'), $this->makeResponse(), ['id' => $id]),
            ];

            foreach ($responses as $response) {
                $this->assertSame(404, $response->getStatusCode());
                $this->assertSame('Liste nicht gefunden.', (string) $response->getBody());
            }
        }

        $fresh = $foreign->fresh();
        $this->assertNotNull($fresh);
        $this->assertSame('Fremde Liste', $fresh->title);
        $this->assertNull($fresh->archived_at);
    }

    public function testAMemberWithoutTheRightIsStoppedByTheMiddleware(): void
    {
        $_SESSION = [
            'user_id' => (int) $this->representative->id,
            'can_manage_own_voice_group' => true,
            'voice_group_ids' => [(int) $this->group->id],
        ];

        $response = (new RoleMiddleware(requiresVoiceGroupLists: true))->process(
            $this->makeRequest('GET', '/voice-group-lists'),
            new class implements RequestHandlerInterface {
                public function handle(ServerRequestInterface $request): ResponseInterface
                {
                    return new Response(200);
                }
            }
        );

        $this->assertSame(403, $response->getStatusCode());
    }

    public function testTheListPageShowsRowsColumnsAndProgress(): void
    {
        $other = $this->makeMember($this->group, null, true, 'Zweitmitglied');
        $list = $this->makeList($this->group);
        $column = $this->makeColumn($list);
        $this->tick($column, $this->representative);

        $html = $this->showHtml($list);

        $this->assertStringContainsString('Vertretung', $html);
        $this->assertStringContainsString('Zweitmitglied', $html);
        $this->assertStringContainsString('Beitrag bezahlt', $html);
        $this->assertStringContainsString('1 / 2', $html);
        $this->assertNotNull($other->id);
    }

    public function testTheOpenFilterShowsOnlyMembersWithoutTick(): void
    {
        $this->makeMember($this->group, null, true, 'Offenmitglied');
        $list = $this->makeList($this->group);
        $column = $this->makeColumn($list);
        $this->tick($column, $this->representative);

        $html = $this->showHtml($list, ['open' => (string) $column->id]);

        $this->assertStringContainsString('Offenmitglied', $html);
        $this->assertStringNotContainsString((new NameFormatterService())->formatPerson($this->representative), $html);
    }

    public function testArchiveRestoreAndDelete(): void
    {
        $list = $this->makeList($this->group);
        $column = $this->makeColumn($list);
        $entry = $this->tick($column, $this->representative);
        $args = ['id' => (string) $list->id];

        $this->controller()->archive($this->makeRequest('POST', '/x'), $this->makeResponse(), $args);
        $this->assertNotNull($list->fresh()?->archived_at);
        $this->assertTrue($this->hasEvent($this->logs, 'voice_group_list.archived'));

        $this->controller()->restore($this->makeRequest('POST', '/x'), $this->makeResponse(), $args);
        $this->assertNull($list->fresh()?->archived_at);

        $response = $this->controller()->delete($this->makeRequest('POST', '/x'), $this->makeResponse(), $args);
        $this->assertRedirect($response, '/voice-group-lists');
        $this->assertNull(VoiceGroupList::find($list->id));
        $this->assertNull(VoiceGroupListEntry::find($entry->id));
        $this->assertTrue($this->hasEvent($this->logs, 'voice_group_list.deleted'));
    }

    public function testAnArchivedListCannotBeRenamed(): void
    {
        $list = $this->makeList($this->group);
        $list->update(['archived_at' => '2026-10-01 10:00:00']);

        $this->controller()->update(
            $this->makeRequest('POST', '/voice-group-lists/' . $list->id, ['title' => 'Neu']),
            $this->makeResponse(),
            ['id' => (string) $list->id]
        );

        $this->assertSame('Die Liste ist archiviert und nur lesbar.', $_SESSION['error'] ?? null);
        $this->assertSame('Sommerkonzert', $list->fresh()?->title);
    }

    public function testLogsNeverContainTheTitle(): void
    {
        $controller = $this->controller();
        $controller->create($this->makeRequest('POST', '/voice-group-lists', ['title' => 'Vertraulich Eins']), $this->makeResponse());
        $list = VoiceGroupList::where('title', 'Vertraulich Eins')->firstOrFail();
        $args = ['id' => (string) $list->id];
        $controller->update(
            $this->makeRequest('POST', '/voice-group-lists/' . $list->id, ['title' => 'Vertraulich Zwei']),
            $this->makeResponse(),
            $args
        );
        $controller->archive($this->makeRequest('POST', '/x'), $this->makeResponse(), $args);
        $controller->restore($this->makeRequest('POST', '/x'), $this->makeResponse(), $args);
        $controller->delete($this->makeRequest('POST', '/x'), $this->makeResponse(), $args);

        $events = [];
        foreach ($this->logs->getRecords() as $record) {
            $events[] = $record->context['event'] ?? null;
            $this->assertStringNotContainsString('Vertraulich', json_encode($record->toArray()) ?: '');
        }
        $this->assertSame([
            'voice_group_list.created',
            'voice_group_list.updated',
            'voice_group_list.archived',
            'voice_group_list.restored',
            'voice_group_list.deleted',
        ], $events);
    }

    /** @param array<string, string> $query */
    private function showHtml(VoiceGroupList $list, array $query = []): string
    {
        $response = $this->controller()->show(
            $this->makeRequest('GET', '/voice-group-lists/' . $list->id, [], $query),
            $this->makeResponse(),
            ['id' => (string) $list->id]
        );
        $this->assertSame(200, $response->getStatusCode());

        return (string) $response->getBody();
    }

    private function tick(VoiceGroupListColumn $column, User $member): VoiceGroupListEntry
    {
        return VoiceGroupListEntry::create([
            'column_id' => $column->id,
            'user_id' => $member->id,
            'checked_at' => '2026-10-26 10:00:00',
            'updated_by_user_id' => $this->representative->id,
        ]);
    }

    private function controller(): VoiceGroupListController
    {
        [$logger, $handler] = $this->logger();
        $this->logs ??= $handler;
        $logger->setHandlers([$this->logs]);

        return new VoiceGroupListController(
            $this->createAppTwig('/voice-group-lists'),
            new VoiceGroupListPolicy($_SESSION),
            new VoiceGroupListRows(),
            new NameFormatterService(),
            $logger
        );
    }
}
