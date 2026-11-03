<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Controllers\PollController;
use App\Models\Poll;
use App\Models\PollVote;
use App\Models\User;
use App\Policies\PollPolicy;
use App\Services\NameFormatterService;
use App\Services\PollAudienceService;
use App\Services\PollLabels;
use App\Services\PollResults;
use App\Services\PollVoteService;
use Carbon\Carbon;
use Illuminate\Database\Capsule\Manager as Capsule;
use Monolog\Handler\TestHandler;
use PHPUnit\Framework\TestCase;
use Psr\Http\Message\ResponseInterface;
use Tests\Unit\Bootstrap;

/**
 * Umfragen ansehen, abstimmen und verwalten - über den Controller und die echten
 * Templates.
 */
final class PollFeatureTest extends TestCase
{
    use TestHttpHelpers;
    use TwigViewStubs;
    use PollFixtures;

    private User $member;
    private TestHandler $logs;

    protected function setUp(): void
    {
        parent::setUp();
        Bootstrap::setupTestDatabase();
        Capsule::connection()->beginTransaction();

        $this->member = $this->makePollMember('Mitglied');
        $_SESSION = $this->pollSession($this->member);
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        $connection = Capsule::connection();
        if ($connection->transactionLevel() > 0) {
            $connection->rollBack();
        }

        $_SESSION = [];
        parent::tearDown();
    }

    public function testForeignAndMissingPollsAnswerIdentically(): void
    {
        $other = $this->makePollMember();
        $foreign = $this->makePoll(audience: ['user' => [(int) $other->id]]);
        $missingId = (int) $foreign->id + 100000;

        foreach (['show', 'vote'] as $action) {
            $foreignResponse = $this->call($action, (int) $foreign->id, ['option_ids' => [$this->optionId($foreign, 0)]]);
            $missingResponse = $this->call($action, $missingId, ['option_ids' => [$this->optionId($foreign, 0)]]);

            $this->assertSame(404, $foreignResponse->getStatusCode(), $action);
            $this->assertSame($missingResponse->getStatusCode(), $foreignResponse->getStatusCode(), $action);
            $this->assertSame((string) $missingResponse->getBody(), (string) $foreignResponse->getBody(), $action);
        }
        $this->assertSame(0, PollVote::where('poll_id', $foreign->id)->count());
    }

    public function testResultsStayHiddenBeforeOwnVoteEvenInTheHtml(): void
    {
        $other = $this->makePollMember('Zeller');
        $poll = $this->makePoll();
        $this->voteAs($other, $poll, [1]);

        $html = $this->showHtml($poll);

        $this->assertStringContainsString('Das Ergebnis siehst du nach deiner Stimme.', $html);
        $this->assertStringContainsString('name="option_ids[]"', $html);
        $this->assertStringNotContainsString('poll-result', $html);
        $this->assertStringNotContainsString('100 %', $html);
        $this->assertStringNotContainsString('Zeller', $html);
        $this->assertStringNotContainsString('haben abgestimmt', $html);
    }

    public function testAfterVotingTheBallotTurnsIntoTheResult(): void
    {
        // Feste Zielgruppe: "alle Mitglieder" hinge an dem, was sonst in der
        // Testdatenbank steht.
        $poll = $this->makePoll(audience: ['user' => [(int) $this->member->id]]);

        $response = $this->call('vote', (int) $poll->id, ['option_ids' => [(string) $this->optionId($poll, 1)]]);
        $this->assertRedirect($response, '/polls/' . $poll->id);

        $html = $this->showHtml($poll);
        $this->assertStringContainsString('poll-result', $html);
        $this->assertStringContainsString('Deine Wahl', $html);
        $this->assertStringContainsString('1 von 1 haben abgestimmt', $html);
        $this->assertStringContainsString('Stimme ändern', $html);
        $this->assertStringNotContainsString('name="option_ids[]"', $html);
    }

    public function testChangingANamedVoteShowsTheBallotWithTheOwnChoice(): void
    {
        $poll = $this->makePoll();
        $this->voteAs($this->member, $poll, [2]);

        $html = $this->showHtml($poll, ['change' => '1']);

        $this->assertMatchesRegularExpression(
            '/value="' . $this->optionId($poll, 2) . '"\s+checked/',
            $html
        );
    }

    public function testAnAnonymousVoteShowsNoOwnChoiceAndNoChangeLink(): void
    {
        $poll = $this->makePoll(anonymous: true);
        $before = $this->showHtml($poll);
        $this->assertStringContainsString('Anonyme Abstimmung: Deine Stimme lässt sich danach nicht mehr ändern.', $before);

        $this->voteAs($this->member, $poll, [0]);
        $html = $this->showHtml($poll, ['change' => '1']);

        $this->assertStringContainsString('Deine Stimme wurde anonym gezählt.', $html);
        $this->assertStringNotContainsString('Deine Wahl', $html);
        $this->assertStringNotContainsString('Stimme ändern', $html);
        $this->assertStringNotContainsString('name="option_ids[]"', $html);
    }

    public function testAClosedPollShowsTheResultWithTheWinnerToTheAudience(): void
    {
        $other = $this->makePollMember();
        $poll = $this->makePoll();
        $this->voteAs($other, $poll, [1]);
        $poll->update(['closes_at' => Carbon::now()->subMinute()]);

        $html = $this->showHtml($poll);

        $this->assertStringContainsString('poll-result__row--winner', $html);
        $this->assertStringContainsString('Beendet', $html);
        $this->assertStringContainsString('Ergebnis: Salzkammergut', $html);
        $this->assertStringNotContainsString('name="option_ids[]"', $html);
    }

    public function testAClosedTieNamesAllLeaders(): void
    {
        $poll = $this->makePoll();
        $this->voteAs($this->makePollMember(), $poll, [0]);
        $this->voteAs($this->makePollMember(), $poll, [1]);
        $poll->update(['closes_at' => Carbon::now()->subMinute()]);

        $this->assertStringContainsString('Gleichstand: Wachau und Salzkammergut', $this->showHtml($poll));
    }

    public function testAClosedPollWithoutVotes(): void
    {
        $poll = $this->makePoll(closesAt: Carbon::now()->subMinute());

        $this->assertStringContainsString('Es wurde nicht abgestimmt.', $this->showHtml($poll));
    }

    public function testAManagerOutsideTheAudienceSeesTheResultAndCannotVote(): void
    {
        $other = $this->makePollMember();
        $poll = $this->makePoll(audience: ['user' => [(int) $other->id]]);
        $_SESSION = $this->pollSession($this->member, true);

        $html = $this->showHtml($poll);
        $this->assertStringContainsString('Du gehörst nicht zur Zielgruppe.', $html);
        $this->assertStringContainsString('poll-result', $html);

        $this->call('vote', (int) $poll->id, ['option_ids' => [(string) $this->optionId($poll, 0)]]);
        $this->assertSame(0, PollVote::where('poll_id', $poll->id)->count());
    }

    public function testAManagerInTheAudienceVotesAndMayPeekAtTheInterimResult(): void
    {
        $poll = $this->makePoll();
        $_SESSION = $this->pollSession($this->member, true);

        $ballot = $this->showHtml($poll);
        $this->assertStringContainsString('name="option_ids[]"', $ballot);
        $this->assertStringContainsString('href="/polls/' . $poll->id . '?results=1"', $ballot);

        // Vom Zwischenstand führt ein Weg zurück zum Stimmzettel.
        $peek = $this->showHtml($poll, ['results' => '1']);
        $this->assertStringContainsString('poll-result', $peek);
        $this->assertStringContainsString('Zum Stimmzettel', $peek);
    }

    public function testAnAnonymousOpenPollOffersNoInterimResult(): void
    {
        $poll = $this->makePoll(anonymous: true);
        $_SESSION = $this->pollSession($this->member, true);

        $this->assertStringNotContainsString('?results=1', $this->showHtml($poll));
    }

    public function testManagerActionsSitInOneDropdownMembersSeeNone(): void
    {
        $poll = $this->makePoll();

        $memberHtml = $this->showHtml($poll);
        $this->assertStringNotContainsString('Umfrage verwalten', $memberHtml);
        $this->assertStringNotContainsString('/polls/' . $poll->id . '/delete', $memberHtml);

        $_SESSION = $this->pollSession($this->member, true);
        $html = $this->showHtml($poll);
        $this->assertSame(1, preg_match(
            '#<div class="dropdown">\s*<button[^>]*data-bs-toggle="dropdown"[^>]*>\s*Umfrage verwalten\s*</button>'
            . '\s*<ul class="dropdown-menu dropdown-menu-end">(.*?)</ul>#s',
            $html,
            $menu
        ));
        $this->assertStringContainsString('href="/polls/' . $poll->id . '/edit"', $menu[1]);
        $this->assertStringContainsString('action="/polls/' . $poll->id . '/close"', $menu[1]);
        $this->assertStringContainsString('action="/polls/' . $poll->id . '/delete"', $menu[1]);
        $this->assertStringContainsString('dropdown-item text-danger', $menu[1]);

        $poll->update(['closes_at' => Carbon::now()->subMinute()]);
        $this->assertStringNotContainsString('/close"', $this->showHtml($poll));
    }

    public function testAVoteAfterTheDeadlineGoesBackToTheResult(): void
    {
        $poll = $this->makePoll(closesAt: Carbon::now()->subSecond());

        $response = $this->call('vote', (int) $poll->id, ['option_ids' => [(string) $this->optionId($poll, 0)]]);

        $this->assertRedirect($response, '/polls/' . $poll->id);
        $this->assertSame('Die Abstimmung ist bereits beendet.', $_SESSION['error'] ?? null);
    }

    public function testAForgottenAnswerIsShownAboveTheRows(): void
    {
        $poll = $this->makePoll(multiple: true);

        $this->call('vote', (int) $poll->id, []);
        $html = $this->showHtml($poll);

        $this->assertStringContainsString('Bitte eine Antwort wählen.', $html);
        $this->assertStringContainsString('Mehrere Antworten wählbar', $html);
    }

    public function testTheOverviewPutsMissingAnswersFirst(): void
    {
        $answered = $this->makePoll(title: 'Schon beantwortet', closesAt: Carbon::now()->addDays(1));
        $this->makePoll(title: 'Noch offen', closesAt: Carbon::now()->addDays(5));
        $this->makePoll(title: 'Längst vorbei', closesAt: Carbon::now()->subDays(2));
        $this->voteAs($this->member, $answered, [0]);

        $html = (string) $this->controller()->index($this->makeRequest('GET', '/polls'), $this->makeResponse())->getBody();

        $this->assertLessThan(strpos($html, 'Schon beantwortet'), strpos($html, 'Noch offen'));
        $this->assertLessThan(strpos($html, 'Längst vorbei'), strpos($html, 'Schon beantwortet'));
        $this->assertStringContainsString('Antwort fehlt', $html);
        $this->assertStringContainsString('Abgestimmt', $html);
        $this->assertStringNotContainsString('Neue Umfrage', $html);
    }

    public function testCreatingAPollWithTheDeadlineAtTheEndOfTheDay(): void
    {
        $_SESSION = $this->pollSession($this->member, true);
        $response = $this->controller()->create($this->makeRequest('POST', '/polls', $this->formData([
            'closes_on' => Carbon::now()->addDays(3)->format('Y-m-d'),
        ])), $this->makeResponse());

        $poll = Poll::where('title', 'T-Shirt-Farbe?')->firstOrFail();
        $this->assertRedirect($response, '/polls/' . $poll->id);
        $this->assertSame(Carbon::now()->addDays(3)->format('Y-m-d') . ' 23:59:59', $poll->closes_at?->format('Y-m-d H:i:s'));
        $this->assertSame(['Rot', 'Blau'], $poll->options()->pluck('label')->all());
        $this->assertTrue($poll->is_anonymous);
        $this->assertSame((int) $this->member->id, $poll->created_by_user_id);
        $this->assertTrue((new PollAudienceService())->isMember($poll, (int) $this->member->id));

        $record = $this->recordFor($this->logs, 'poll.created');
        $this->assertNotNull($record);
        $this->assertSame((int) $poll->id, $record->context['poll_id']);
        $this->assertStringNotContainsString('T-Shirt', json_encode($record->toArray()) ?: '');
    }

    public function testInvalidFormsAreRejectedAndRedisplayed(): void
    {
        $_SESSION = $this->pollSession($this->member, true);
        $cases = [
            'Bitte eine Frage eingeben.' => ['title' => ' '],
            'Bitte mindestens zwei Antworten angeben.' => ['options' => ['Nur eine', '']],
            'Eine Antwort darf höchstens 200 Zeichen lang sein.' => ['options' => ['Kurz', str_repeat('x', 201)]],
            'Höchstens 20 Antworten.' => ['options' => array_map('strval', range(1, 21))],
            'Jede Antwort darf nur einmal vorkommen.' => ['options' => ['Ja', 'Nein', ' ja ']],
            'Bitte ein gültiges Datum angeben.' => ['closes_on' => '31.12.'],
            'Die Frist darf nicht in der Vergangenheit liegen.' => ['closes_on' => Carbon::now()->subDay()->format('Y-m-d')],
            'Bitte mindestens eine Zielgruppe angeben.' => ['audience' => []],
        ];

        foreach ($cases as $message => $override) {
            $response = $this->controller()->create(
                $this->makeRequest('POST', '/polls', $this->formData($override)),
                $this->makeResponse()
            );

            $this->assertSame(422, $response->getStatusCode(), $message);
            $this->assertStringContainsString(htmlspecialchars($message, ENT_QUOTES), (string) $response->getBody(), $message);
        }
        $this->assertSame(0, Poll::where('title', 'T-Shirt-Farbe?')->count());
    }

    public function testTheFirstVoteLocksOptionsAndChoiceTypeButNotTheRest(): void
    {
        $_SESSION = $this->pollSession($this->member, true);
        $poll = $this->makePoll(['Wachau', 'Salzkammergut'], anonymous: false);
        $this->voteAs($this->member, $poll, [0]);
        $newGroup = $this->makePollVoiceGroup($this->member);

        $this->controller()->update($this->makeRequest('POST', '/polls/' . $poll->id, $this->formData([
            'title' => 'Ausflug: neues Ziel?',
            'options' => ['Ganz', 'Anders', 'Neu'],
            'allows_multiple' => '1',
            'is_anonymous' => '1',
            'closes_on' => Carbon::now()->addDays(10)->format('Y-m-d'),
            'audience' => [['conditions' => ['voice_group' => [(string) $newGroup->id]]]],
        ])), $this->makeResponse(), ['id' => (string) $poll->id]);

        $poll = $poll->fresh();
        $this->assertSame('Ausflug: neues Ziel?', $poll->title);
        $this->assertSame(['Wachau', 'Salzkammergut'], $poll->options()->pluck('label')->all());
        $this->assertFalse($poll->allows_multiple);
        $this->assertFalse($poll->is_anonymous);
        $this->assertSame(Carbon::now()->addDays(10)->format('Y-m-d'), $poll->closes_at?->format('Y-m-d'));
        $this->assertSame([['voice_group' => [(int) $newGroup->id]]], (new PollAudienceService())->conditionSets($poll));
        $this->assertSame(1, PollVote::where('poll_id', $poll->id)->count());

        $form = (string) $this->controller()->editForm(
            $this->makeRequest('GET', '/polls/' . $poll->id . '/edit'),
            $this->makeResponse(),
            ['id' => (string) $poll->id]
        )->getBody();
        $this->assertStringContainsString('Antworten und Auswahlart sind gesperrt, weil schon abgestimmt wurde.', $form);
    }

    public function testAnonymityCannotChangeEvenWithoutVotes(): void
    {
        $_SESSION = $this->pollSession($this->member, true);
        $poll = $this->makePoll(['A', 'B'], anonymous: true);

        $this->controller()->update($this->makeRequest('POST', '/polls/' . $poll->id, $this->formData([
            'options' => ['A', 'B', 'C'],
            'is_anonymous' => null,
        ])), $this->makeResponse(), ['id' => (string) $poll->id]);

        $poll = $poll->fresh();
        $this->assertTrue($poll->is_anonymous);
        $this->assertSame(['A', 'B', 'C'], $poll->options()->pluck('label')->all());
    }

    public function testTheEditFormStatesAnonymityAndAnEarlyEndAsText(): void
    {
        $_SESSION = $this->pollSession($this->member, true);
        $poll = $this->makePoll(['A', 'B'], anonymous: true, closesAt: Carbon::now()->subMinute());

        $form = (string) $this->controller()->editForm(
            $this->makeRequest('GET', '/polls/' . $poll->id . '/edit'),
            $this->makeResponse(),
            ['id' => (string) $poll->id]
        )->getBody();

        // Ein ausgegrauter Schalter sagt nicht, ob er an oder aus ist.
        $this->assertStringContainsString('Anonyme Abstimmung', $form);
        $this->assertStringNotContainsString('id="poll_is_anonymous"', $form);
        $this->assertStringContainsString('Ein späteres Datum öffnet die Abstimmung wieder.', $form);
    }

    public function testClosingNowAndExtendingReopens(): void
    {
        $_SESSION = $this->pollSession($this->member, true);
        $poll = $this->makePoll();

        $this->controller()->close($this->makeRequest('POST', '/x'), $this->makeResponse(), ['id' => (string) $poll->id]);
        $this->assertTrue($poll->fresh()->isClosed());

        $this->controller()->update($this->makeRequest('POST', '/polls/' . $poll->id, $this->formData([
            'closes_on' => Carbon::now()->addDay()->format('Y-m-d'),
        ])), $this->makeResponse(), ['id' => (string) $poll->id]);
        $this->assertFalse($poll->fresh()->isClosed());
        $this->assertSame('23:59:59', $poll->fresh()->closes_at?->format('H:i:s'));
    }

    public function testEditingAPollClosedEarlyKeepsItClosed(): void
    {
        $_SESSION = $this->pollSession($this->member, true);
        $poll = $this->makePoll();
        Carbon::setTestNow(Carbon::now()->setTime(14, 0));
        $this->controller()->close($this->makeRequest('POST', '/x'), $this->makeResponse(), ['id' => (string) $poll->id]);
        $closedAt = $poll->fresh()->closes_at?->format('Y-m-d H:i:s');

        // Nur der Tippfehler im Titel: Das Formular schickt das unveränderte Datum zurück.
        $this->controller()->update($this->makeRequest('POST', '/polls/' . $poll->id, $this->formData([
            'title' => 'Ausflug im Mai: wohin genau?',
            'closes_on' => Carbon::now()->format('Y-m-d'),
        ])), $this->makeResponse(), ['id' => (string) $poll->id]);

        $poll = $poll->fresh();
        $this->assertSame('Ausflug im Mai: wohin genau?', $poll->title);
        $this->assertTrue($poll->isClosed());
        $this->assertSame($closedAt, $poll->closes_at?->format('Y-m-d H:i:s'));
    }

    public function testAnAnonymousOpenPollShowsNoCountsToAnyone(): void
    {
        $other = $this->makePollMember();
        $poll = $this->makePoll(anonymous: true, audience: ['user' => [(int) $this->member->id, (int) $other->id]]);
        $this->voteAs($other, $poll, [1]);
        $this->voteAs($this->member, $poll, [0]);

        // Wer neu lädt, während jemand abstimmt, sähe sonst an der Änderung, wofür.
        foreach ([false, true] as $canManage) {
            $_SESSION = $this->pollSession($this->member, $canManage);
            $html = $this->showHtml($poll, ['results' => '1']);

            $this->assertStringContainsString('2 von 2 haben abgestimmt', $html);
            $this->assertStringContainsString(
                'Das Ergebnis erscheint am ' . (new PollLabels())->date($poll) . '<',
                $html
            );
            // Die Frage bleibt stehen: dieselben Zeilen, nur ohne Zahlen.
            $this->assertStringContainsString('Salzkammergut', $html);
            $this->assertStringNotContainsString('die Prozente beziehen sich', $html);
            $this->assertStringNotContainsString('poll-result__count', $html);
            $this->assertStringNotContainsString('%', strip_tags(explode('poll-result', $html, 2)[1] ?? ''));
        }

        $poll->update(['closes_at' => Carbon::now()->subMinute()]);
        $this->assertStringContainsString('poll-result__count', $this->showHtml($poll));
    }

    public function testAVoteErrorIsShownEvenWhenNoBallotFollows(): void
    {
        $poll = $this->makePoll(anonymous: true);
        $this->voteAs($this->member, $poll, [0]);

        // Zweiter Tab: dieselbe anonyme Umfrage noch einmal abgeschickt.
        $this->call('vote', (int) $poll->id, ['option_ids' => [(string) $this->optionId($poll, 1)]]);

        $this->assertStringContainsString(
            'Deine Stimme wurde bereits anonym gezählt und lässt sich nicht mehr ändern.',
            $this->showHtml($poll)
        );
    }

    public function testAVoteErrorStaysWithItsOwnPoll(): void
    {
        $first = $this->makePoll(multiple: true);
        $second = $this->makePoll(title: 'Probenwunsch?');

        $this->call('vote', (int) $first->id, []);

        $this->assertStringNotContainsString('Bitte eine Antwort wählen.', $this->showHtml($second));
        $this->assertStringContainsString('Bitte eine Antwort wählen.', $this->showHtml($first));
    }

    public function testParticipantsOutsideTheChangedAudienceShowAsVoteCount(): void
    {
        $alto = $this->makePollMember('Alt');
        $soprano = $this->makePollMember('Sopran');
        $poll = $this->makePoll(audience: ['user' => [(int) $alto->id, (int) $this->member->id]]);
        $this->voteAs($alto, $poll, [0]);
        (new PollAudienceService())->setAudience($poll, [['user' => [(int) $soprano->id, (int) $this->member->id]]]);
        $_SESSION = $this->pollSession($this->member, true);

        $html = $this->showHtml($poll, ['results' => '1']);

        $this->assertStringContainsString('1 Stimme', $html);
        $this->assertStringNotContainsString('1 von 2 haben abgestimmt', $html);
    }

    public function testDeletingAPollAndTheLogNamesOnlyIds(): void
    {
        $_SESSION = $this->pollSession($this->member, true);
        $poll = $this->makePoll(title: 'Vertraulich');

        $response = $this->controller()->delete($this->makeRequest('POST', '/x'), $this->makeResponse(), ['id' => (string) $poll->id]);

        $this->assertRedirect($response, '/polls');
        $this->assertNull(Poll::find($poll->id));
        $record = $this->recordFor($this->logs, 'poll.deleted');
        $this->assertNotNull($record);
        $this->assertStringNotContainsString('Vertraulich', json_encode($record->toArray()) ?: '');
    }

    /**
     * @param array<string, mixed> $override
     * @return array<string, mixed>
     */
    private function formData(array $override = []): array
    {
        $data = array_merge([
            'title' => 'T-Shirt-Farbe?',
            'description' => '',
            'options' => ['Rot', '', 'Blau'],
            'is_anonymous' => '1',
            'closes_on' => Carbon::now()->addDays(7)->format('Y-m-d'),
            'audience' => [['all' => '1']],
        ], $override);

        return array_filter($data, static fn ($value): bool => $value !== null);
    }

    /** @param list<int> $indexes */
    private function voteAs(User $user, Poll $poll, array $indexes): void
    {
        (new PollVoteService(new PollAudienceService()))->vote(
            $poll,
            (int) $user->id,
            array_map(fn (int $index): int => $this->optionId($poll, $index), $indexes)
        );
    }

    /** @param array<string, string> $query */
    private function showHtml(Poll $poll, array $query = []): string
    {
        $response = $this->controller()->show(
            $this->makeRequest('GET', '/polls/' . $poll->id, [], $query),
            $this->makeResponse(),
            ['id' => (string) $poll->id]
        );
        $this->assertSame(200, $response->getStatusCode());

        return (string) $response->getBody();
    }

    /** @param array<string, mixed> $body */
    private function call(string $action, int $id, array $body = []): ResponseInterface
    {
        $method = $action === 'show' ? 'GET' : 'POST';

        return $this->controller()->{$action}(
            $this->makeRequest($method, '/polls/' . $id, $body),
            $this->makeResponse(),
            ['id' => (string) $id]
        );
    }

    private function controller(): PollController
    {
        [$logger, $handler] = $this->logger();
        $this->logs ??= $handler;
        $logger->setHandlers([$this->logs]);
        $audience = new PollAudienceService();

        return new PollController(
            $this->createAppTwig('/polls'),
            new PollPolicy($_SESSION),
            $audience,
            new PollVoteService($audience, $logger),
            new PollResults($audience),
            new NameFormatterService(),
            null,
            $logger
        );
    }
}
