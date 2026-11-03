<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\Poll;
use App\Models\PollVote;
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
 * Abstimmen und Zählen.
 *
 * Jede Abweisung hinterlässt keine Spur in der Datenbank, und eine anonyme
 * Stimme lässt sich keiner Person zuordnen - weder über die Stimme selbst noch
 * über das Protokoll.
 */
final class PollVoteFeatureTest extends TestCase
{
    use PollFixtures;

    private TestHandler $logs;

    protected function setUp(): void
    {
        parent::setUp();
        Bootstrap::setupTestDatabase();
        Capsule::connection()->beginTransaction();
        $this->logs = new TestHandler();
    }

    protected function tearDown(): void
    {
        $connection = Capsule::connection();
        if ($connection->transactionLevel() > 0) {
            $connection->rollBack();
        }

        parent::tearDown();
    }

    public function testAnAnonymousVoteCannotBeTracedToThePerson(): void
    {
        $member = $this->makePollMember();
        $poll = $this->makePoll(anonymous: true);
        $optionId = $this->optionId($poll, 1);

        $this->service()->vote($poll, (int) $member->id, [$optionId]);

        $vote = PollVote::where('poll_id', $poll->id)->firstOrFail();
        $this->assertNull($vote->user_id);
        $this->assertMatchesRegularExpression('/^[0-9a-f]{8}-[0-9a-f]{4}-4[0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/', $vote->id);
        $this->assertTrue($this->service()->hasVoted($poll, (int) $member->id));

        $records = $this->logs->getRecords();
        $this->assertCount(1, $records);
        $this->assertSame(['event' => 'poll.voted', 'poll_id' => (int) $poll->id], $records[0]->context);
        $serialized = json_encode($records[0]->toArray()) ?: '';
        $this->assertStringNotContainsString('"' . $member->id . '"', $serialized);
        $this->assertStringNotContainsString((string) $optionId, json_encode($records[0]->context) ?: '');
    }

    public function testASecondAnonymousVoteIsRejected(): void
    {
        $member = $this->makePollMember();
        $poll = $this->makePoll(anonymous: true);
        $this->service()->vote($poll, (int) $member->id, [$this->optionId($poll, 0)]);

        $this->assertRejected(PollVoteException::ALREADY_VOTED, $poll, (int) $member->id, [$this->optionId($poll, 1)]);
        $this->assertSame(1, PollVote::where('poll_id', $poll->id)->count());
        $this->assertSame((int) $this->optionId($poll, 0), (int) PollVote::where('poll_id', $poll->id)->value('option_id'));
    }

    public function testAnOptionOfAnotherPollIsRejected(): void
    {
        $member = $this->makePollMember();
        $poll = $this->makePoll();
        $other = $this->makePoll();

        $this->assertRejected(PollVoteException::INVALID_OPTION, $poll, (int) $member->id, [$this->optionId($other, 0)]);
        $this->assertNoTrace($poll);
    }

    public function testTwoOptionsInASingleChoicePollAreRejected(): void
    {
        $member = $this->makePollMember();
        $poll = $this->makePoll();

        $this->assertRejected(
            PollVoteException::TOO_MANY,
            $poll,
            (int) $member->id,
            [$this->optionId($poll, 0), $this->optionId($poll, 1)]
        );
        $this->assertNoTrace($poll);
    }

    public function testAnEmptyChoiceIsRejected(): void
    {
        $member = $this->makePollMember();
        $poll = $this->makePoll();

        $this->assertRejected(PollVoteException::EMPTY_CHOICE, $poll, (int) $member->id, []);
        $this->assertNoTrace($poll);
    }

    public function testVotingAfterTheDeadlineIsRejected(): void
    {
        $member = $this->makePollMember();
        $poll = $this->makePoll(closesAt: Carbon::now()->subSecond());

        $this->assertRejected(PollVoteException::CLOSED, $poll, (int) $member->id, [$this->optionId($poll, 0)]);
        $this->assertNoTrace($poll);
    }

    public function testVotingOutsideTheAudienceIsRejected(): void
    {
        $member = $this->makePollMember();
        $outsider = $this->makePollMember();
        $poll = $this->makePoll(audience: ['user' => [(int) $member->id]]);

        $this->assertRejected(PollVoteException::NOT_IN_AUDIENCE, $poll, (int) $outsider->id, [$this->optionId($poll, 0)]);
        $this->assertNoTrace($poll);
    }

    public function testChangingANamedVoteReplacesItAndKeepsTheCountRight(): void
    {
        $member = $this->makePollMember();
        $other = $this->makePollMember();
        $poll = $this->makePoll();
        $this->service()->vote($poll, (int) $other->id, [$this->optionId($poll, 0)]);
        $this->service()->vote($poll, (int) $member->id, [$this->optionId($poll, 0)]);

        $this->service()->vote($poll, (int) $member->id, [$this->optionId($poll, 2)]);

        $results = $this->results()->compute($poll, (int) $member->id);
        $this->assertSame([1, 0, 1], array_column($results['options'], 'count'));
        $this->assertSame(2, $results['participant_count']);
        $this->assertSame([$this->optionId($poll, 2)], $results['my_option_ids']);
        $this->assertSame(1, Capsule::table('poll_participants')->where('poll_id', $poll->id)->where('user_id', $member->id)->count());
    }

    public function testMultipleChoicePercentRelatesToVoters(): void
    {
        $a = $this->makePollMember();
        $b = $this->makePollMember();
        $poll = $this->makePoll(['Rot', 'Grün', 'Blau'], multiple: true);
        $this->service()->vote($poll, (int) $a->id, [$this->optionId($poll, 0), $this->optionId($poll, 1)]);
        $this->service()->vote($poll, (int) $b->id, [$this->optionId($poll, 0)]);

        $results = $this->results()->compute($poll, null);

        $this->assertSame([2, 1, 0], array_column($results['options'], 'count'));
        $this->assertSame([100, 50, 0], array_column($results['options'], 'percent'));
        $this->assertSame([true, false, false], array_column($results['options'], 'is_winner'));
    }

    public function testATieMarksAllLeaders(): void
    {
        $a = $this->makePollMember();
        $b = $this->makePollMember();
        $poll = $this->makePoll(['A', 'B', 'C']);
        $this->service()->vote($poll, (int) $a->id, [$this->optionId($poll, 0)]);
        $this->service()->vote($poll, (int) $b->id, [$this->optionId($poll, 1)]);

        $results = $this->results()->compute($poll, null);

        $this->assertSame([true, true, false], array_column($results['options'], 'is_winner'));
    }

    public function testNoVotesMeansNoWinner(): void
    {
        $results = $this->results()->compute($this->makePoll(), null);

        $this->assertSame(0, $results['participant_count']);
        $this->assertSame([false, false, false], array_column($results['options'], 'is_winner'));
    }

    public function testNamedResultsListVotersByLastNameAnonymousOnesDoNot(): void
    {
        $zeller = $this->makePollMember('Zeller');
        $adler = $this->makePollMember('Adler');
        $named = $this->makePoll();
        $this->service()->vote($named, (int) $zeller->id, [$this->optionId($named, 0)]);
        $this->service()->vote($named, (int) $adler->id, [$this->optionId($named, 0)]);

        $voters = $this->results()->compute($named, null)['options'][0]['voters'];
        $this->assertSame(['Adler', 'Zeller'], array_map(static fn ($user): string => (string) $user->last_name, $voters));

        $anonymous = $this->makePoll(anonymous: true);
        $this->service()->vote($anonymous, (int) $adler->id, [$this->optionId($anonymous, 0)]);
        $results = $this->results()->compute($anonymous, (int) $adler->id);
        $this->assertSame([], $results['options'][0]['voters']);
        $this->assertSame([], $results['my_option_ids']);
    }

    public function testParticipationAgainstTheAudience(): void
    {
        $a = $this->makePollMember();
        $b = $this->makePollMember();
        $poll = $this->makePoll(audience: ['user' => [(int) $a->id, (int) $b->id]]);
        $this->service()->vote($poll, (int) $a->id, [$this->optionId($poll, 0)]);

        $results = $this->results()->compute($poll, null);
        $this->assertSame(1, $results['participant_count']);
        $this->assertSame(2, $results['audience_count']);
        $this->assertFalse($results['audience_shrunk']);

        $this->service()->vote($poll, (int) $b->id, [$this->optionId($poll, 1)]);
        (new PollAudienceService())->setAudience($poll, [['user' => [(int) $a->id]]]);
        $this->assertTrue($this->results()->compute($poll, null)['audience_shrunk']);
    }

    /** @param list<int> $optionIds */
    private function assertRejected(string $reason, Poll $poll, int $userId, array $optionIds): void
    {
        try {
            $this->service()->vote($poll, $userId, $optionIds);
            $this->fail('Stimme hätte abgewiesen werden müssen: ' . $reason);
        } catch (PollVoteException $exception) {
            $this->assertSame($reason, $exception->reason);
        }
    }

    private function assertNoTrace(Poll $poll): void
    {
        $this->assertSame(0, PollVote::where('poll_id', $poll->id)->count());
        $this->assertSame(0, Capsule::table('poll_participants')->where('poll_id', $poll->id)->count());
        $this->assertFalse($this->logs->hasInfoRecords());
    }

    private function service(): PollVoteService
    {
        $logger = new Logger('test');
        $logger->pushHandler($this->logs);

        return new PollVoteService(new PollAudienceService(), $logger);
    }

    private function results(): PollResults
    {
        return new PollResults(new PollAudienceService());
    }
}
