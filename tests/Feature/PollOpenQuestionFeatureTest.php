<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Controllers\PollController;
use App\Models\Poll;
use App\Models\PollTextAnswer;
use App\Models\User;
use App\Policies\PollPolicy;
use App\Services\NameFormatterService;
use App\Services\PollAudienceService;
use App\Services\PollResults;
use App\Services\PollVoteException;
use App\Services\PollVoteService;
use Carbon\Carbon;
use Illuminate\Database\Capsule\Manager as Capsule;
use Monolog\Handler\TestHandler;
use Monolog\Logger;
use PHPUnit\Framework\TestCase;
use Tests\Unit\Bootstrap;

/**
 * Offene Fragen: Statt aus festen Antworten zu wählen, schreibt jede Person eine
 * eigene ("Vorschläge für den nächsten Konzerttitel").
 *
 * Für die Anonymität gilt dasselbe wie bei den Stimmen: keine Zeitstempel,
 * keine laufende Id, und solange eine anonyme Frage offen ist, bleiben die
 * Antworten verborgen - ein neu auftauchender Text verriete sonst über den
 * Zeitpunkt, von wem er ist.
 */
final class PollOpenQuestionFeatureTest extends TestCase
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
        $this->logs = new TestHandler();
        $this->member = $this->makePollMember('Mitglied');
        $_SESSION = $this->pollSession($this->member);
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

    public function testTextAnswersCarryNoOrder(): void
    {
        $columns = array_map(
            static fn (object $row): string => (string) $row->COLUMN_NAME,
            Capsule::connection()->select(
                "SELECT COLUMN_NAME FROM information_schema.COLUMNS
                 WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'poll_text_answers' ORDER BY ORDINAL_POSITION"
            )
        );
        $this->assertSame(['id', 'poll_id', 'user_id', 'body'], $columns);

        $poll = $this->openQuestion();
        $answer = PollTextAnswer::create(['poll_id' => $poll->id, 'body' => 'Klangfarben']);
        $this->assertMatchesRegularExpression(
            '/^[0-9a-f]{8}-[0-9a-f]{4}-4[0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/',
            (string) $answer->id
        );

        $poll->delete();
        $this->assertSame(0, PollTextAnswer::where('poll_id', $poll->id)->count());
    }

    public function testAnAnonymousAnswerCannotBeTracedAndIsFinal(): void
    {
        $poll = $this->openQuestion(anonymous: true);

        $this->service()->answer($poll, (int) $this->member->id, '  Klangfarben  ');

        $answer = PollTextAnswer::where('poll_id', $poll->id)->firstOrFail();
        $this->assertNull($answer->user_id);
        $this->assertSame('Klangfarben', $answer->body);
        $this->assertTrue($this->service()->hasVoted($poll, (int) $this->member->id));
        foreach ($this->logs->getRecords() as $record) {
            $this->assertSame(['event' => 'poll.voted', 'poll_id' => (int) $poll->id], $record->context);
        }

        $this->assertRejected(PollVoteException::ALREADY_VOTED, $poll, 'Nachgedacht');
        $this->assertSame(['Klangfarben'], PollTextAnswer::where('poll_id', $poll->id)->pluck('body')->all());
    }

    public function testANamedAnswerIsReplacedNotAdded(): void
    {
        $poll = $this->openQuestion();
        $this->service()->answer($poll, (int) $this->member->id, 'Erster Gedanke');
        $this->service()->answer($poll, (int) $this->member->id, "Zweiter Gedanke\nund noch einer");

        $answers = PollTextAnswer::where('poll_id', $poll->id)->get();
        $this->assertCount(1, $answers);
        $this->assertSame("Zweiter Gedanke\nund noch einer", $answers[0]->body);
        $this->assertSame((int) $this->member->id, $answers[0]->user_id);
    }

    public function testInvalidAnswersAreRejectedWithoutTrace(): void
    {
        $poll = $this->openQuestion();
        $this->assertRejected(PollVoteException::EMPTY_ANSWER, $poll, "  \n ");
        $this->assertRejected(PollVoteException::ANSWER_TOO_LONG, $poll, str_repeat('ä', 501));

        $outsider = $this->makePollMember();
        $narrow = $this->openQuestion(audience: ['user' => [(int) $this->member->id]]);
        $this->assertRejected(PollVoteException::NOT_IN_AUDIENCE, $narrow, 'Hallo', (int) $outsider->id);

        $closed = $this->openQuestion(closesAt: Carbon::now()->subMinute());
        $this->assertRejected(PollVoteException::CLOSED, $closed, 'Zu spät');

        // Eine Auswahl-Umfrage nimmt keinen Text, eine offene Frage keine Auswahl.
        $choice = $this->makePoll();
        $this->assertRejected(PollVoteException::WRONG_KIND, $choice, 'Text statt Auswahl');
        try {
            $this->service()->vote($poll, (int) $this->member->id, [$this->optionId($choice, 0)]);
            $this->fail('Eine offene Frage hätte die Auswahl abweisen müssen.');
        } catch (PollVoteException $exception) {
            $this->assertSame(PollVoteException::WRONG_KIND, $exception->reason);
        }
    }

    public function testExactly500CharactersAreAccepted(): void
    {
        $poll = $this->openQuestion();
        $this->service()->answer($poll, (int) $this->member->id, str_repeat('ä', 500));

        $this->assertSame(1, PollTextAnswer::where('poll_id', $poll->id)->count());
    }

    public function testTheBallotIsATextFieldAndTheResultListsTheAnswers(): void
    {
        $other = $this->makePollMember('Zeller');
        $poll = $this->openQuestion(audience: ['user' => [(int) $this->member->id, (int) $other->id]]);
        $this->service()->answer($poll, (int) $other->id, 'Stimmen der Stadt');

        $ballot = $this->showHtml($poll);
        $this->assertStringContainsString('name="answer"', $ballot);
        $this->assertStringNotContainsString('Stimmen der Stadt', $ballot);

        $response = $this->call('vote', $poll, ['answer' => "Klangfarben\n<b>laut</b>"]);
        $this->assertRedirect($response, '/polls/' . $poll->id);

        $html = $this->showHtml($poll);
        $this->assertStringContainsString('2 von 2 haben geantwortet', $html);
        $this->assertStringContainsString('Stimmen der Stadt', $html);
        $this->assertStringContainsString('Zeller', $html);
        $this->assertStringContainsString('Klangfarben<br />', $html);
        $this->assertStringContainsString('&lt;b&gt;laut&lt;/b&gt;', $html);
        $this->assertStringContainsString('Deine Antwort', $html);
        $this->assertStringContainsString('Antwort ändern', $html);

        $change = $this->showHtml($poll, ['change' => '1']);
        $this->assertStringContainsString("Klangfarben\n&lt;b&gt;laut&lt;/b&gt;</textarea>", $change);
    }

    public function testAnAnonymousOpenQuestionHidesTheAnswersUntilTheDeadline(): void
    {
        $other = $this->makePollMember();
        $poll = $this->openQuestion(anonymous: true);
        $this->service()->answer($poll, (int) $other->id, 'Stimmen der Stadt');
        $this->service()->answer($poll, (int) $this->member->id, 'Klangfarben');

        foreach ([false, true] as $canManage) {
            $_SESSION = $this->pollSession($this->member, $canManage);
            $html = $this->showHtml($poll, ['results' => '1']);
            $this->assertStringContainsString('Deine Antwort wurde anonym gezählt.', $html);
            $this->assertStringNotContainsString('Stimmen der Stadt', $html);
            $this->assertStringNotContainsString('Klangfarben', $html);
        }

        $poll->update(['closes_at' => Carbon::now()->subMinute()]);
        $closed = $this->showHtml($poll);
        $this->assertStringContainsString('Stimmen der Stadt', $closed);
        $this->assertStringContainsString('Klangfarben', $closed);
    }

    public function testAForgottenAnswerIsShownAndTheTextIsKept(): void
    {
        $poll = $this->openQuestion();

        $this->call('vote', $poll, ['answer' => str_repeat('x', 501)]);
        $html = $this->showHtml($poll);

        $this->assertStringContainsString('Eine Antwort darf höchstens 500 Zeichen lang sein.', $html);
        $this->assertStringContainsString(str_repeat('x', 501) . '</textarea>', $html);
        $this->assertSame(0, PollTextAnswer::where('poll_id', $poll->id)->count());
    }

    public function testCreatingAnOpenQuestionNeedsNoOptions(): void
    {
        $_SESSION = $this->pollSession($this->member, true);

        $response = $this->controller()->create($this->makeRequest('POST', '/polls', [
            'title' => 'Vorschläge für den nächsten Konzerttitel',
            'answer_kind' => 'text',
            'options' => ['', ''],
            'allows_multiple' => '1',
            'closes_on' => Carbon::now()->addDays(7)->format('Y-m-d'),
            'audience' => [['all' => '1']],
        ]), $this->makeResponse());

        $poll = Poll::where('title', 'Vorschläge für den nächsten Konzerttitel')->firstOrFail();
        $this->assertRedirect($response, '/polls/' . $poll->id);
        $this->assertSame(Poll::KIND_TEXT, $poll->answer_kind);
        $this->assertFalse($poll->allows_multiple);
        $this->assertSame(0, $poll->options()->count());
    }

    public function testTheKindIsFixedAfterTheStart(): void
    {
        $_SESSION = $this->pollSession($this->member, true);
        $poll = $this->openQuestion();

        $this->controller()->update($this->makeRequest('POST', '/polls/' . $poll->id, [
            'title' => 'Vorschläge, bitte',
            'answer_kind' => 'choice',
            'options' => ['A', 'B'],
            'closes_on' => Carbon::now()->addDays(7)->format('Y-m-d'),
            'audience' => [['all' => '1']],
        ]), $this->makeResponse(), ['id' => (string) $poll->id]);

        $poll = $poll->fresh();
        $this->assertSame('Vorschläge, bitte', $poll->title);
        $this->assertSame(Poll::KIND_TEXT, $poll->answer_kind);
        $this->assertSame(0, $poll->options()->count());

        $form = (string) $this->controller()->editForm(
            $this->makeRequest('GET', '/polls/' . $poll->id . '/edit'),
            $this->makeResponse(),
            ['id' => (string) $poll->id]
        )->getBody();
        $this->assertStringContainsString('Freie Antwort', $form);
        $this->assertStringNotContainsString('name="options[]"', $form);
    }

    public function testTheCreateFormOffersBothKinds(): void
    {
        $_SESSION = $this->pollSession($this->member, true);

        $form = (string) $this->controller()->createForm(
            $this->makeRequest('GET', '/polls/create'),
            $this->makeResponse()
        )->getBody();

        $this->assertStringContainsString('name="answer_kind" value="choice"', $form);
        $this->assertStringContainsString('name="answer_kind" value="text"', $form);
    }

    private function openQuestion(
        bool $anonymous = false,
        ?Carbon $closesAt = null,
        ?array $audience = []
    ): Poll {
        return $this->makePoll(
            anonymous: $anonymous,
            closesAt: $closesAt,
            audience: $audience,
            title: 'Vorschläge für den nächsten Konzerttitel',
            kind: Poll::KIND_TEXT
        );
    }

    private function assertRejected(string $reason, Poll $poll, string $text, ?int $userId = null): void
    {
        $before = PollTextAnswer::where('poll_id', $poll->id)->count();
        try {
            $this->service()->answer($poll, $userId ?? (int) $this->member->id, $text);
            $this->fail('Antwort hätte abgewiesen werden müssen: ' . $reason);
        } catch (PollVoteException $exception) {
            $this->assertSame($reason, $exception->reason);
        }
        $this->assertSame($before, PollTextAnswer::where('poll_id', $poll->id)->count());
    }

    private function service(): PollVoteService
    {
        $logger = new Logger('test');
        $logger->pushHandler($this->logs);

        return new PollVoteService(new PollAudienceService(), $logger);
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
    private function call(string $action, Poll $poll, array $body): \Psr\Http\Message\ResponseInterface
    {
        return $this->controller()->{$action}(
            $this->makeRequest('POST', '/polls/' . $poll->id, $body),
            $this->makeResponse(),
            ['id' => (string) $poll->id]
        );
    }

    private function controller(): PollController
    {
        $audience = new PollAudienceService();

        return new PollController(
            $this->createAppTwig('/polls'),
            new PollPolicy($_SESSION),
            $audience,
            new PollVoteService($audience),
            new PollResults($audience),
            new NameFormatterService()
        );
    }
}
