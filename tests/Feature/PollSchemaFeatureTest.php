<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\AudienceFilter;
use App\Models\Poll;
use App\Models\PollOption;
use App\Models\PollVote;
use Carbon\Carbon;
use Illuminate\Database\Capsule\Manager as Capsule;
use Illuminate\Database\QueryException;
use PHPUnit\Framework\TestCase;
use Tests\Unit\Bootstrap;

/**
 * Tabellen der Umfragen.
 *
 * Kern ist die Anonymität: Weder Teilnahme noch Stimme tragen eine Reihenfolge
 * (keine Zeitstempel, keine laufende Id), sonst ließe sich eine anonyme Stimme
 * über die Reihenfolge der Abstimmenden einer Person zuordnen.
 */
final class PollSchemaFeatureTest extends TestCase
{
    use PollFixtures;

    protected function setUp(): void
    {
        parent::setUp();
        Bootstrap::setupTestDatabase();
        Capsule::connection()->beginTransaction();
    }

    protected function tearDown(): void
    {
        $connection = Capsule::connection();
        if ($connection->transactionLevel() > 0) {
            $connection->rollBack();
        }

        parent::tearDown();
    }

    public function testParticipantsAndVotesCarryNoOrder(): void
    {
        $this->assertSame(['poll_id', 'user_id'], $this->columns('poll_participants'));
        $this->assertSame(['id', 'poll_id', 'option_id', 'user_id'], $this->columns('poll_votes'));

        $idType = Capsule::connection()->selectOne(
            "SELECT DATA_TYPE, EXTRA FROM information_schema.COLUMNS
             WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'poll_votes' AND COLUMN_NAME = 'id'"
        );
        $this->assertSame('char', strtolower((string) $idType->DATA_TYPE));
        $this->assertStringNotContainsString('auto_increment', strtolower((string) $idType->EXTRA));
    }

    public function testVoteIdsAreRandomUuids(): void
    {
        $poll = $this->makePoll();
        $first = PollVote::create(['poll_id' => $poll->id, 'option_id' => $this->optionId($poll, 0)]);
        $second = PollVote::create(['poll_id' => $poll->id, 'option_id' => $this->optionId($poll, 0)]);

        $pattern = '/^[0-9a-f]{8}-[0-9a-f]{4}-4[0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/';
        $this->assertMatchesRegularExpression($pattern, (string) $first->id);
        $this->assertMatchesRegularExpression($pattern, (string) $second->id);
        $this->assertNotSame($first->id, $second->id);
        $this->assertNotNull(PollVote::find($first->id));
    }

    public function testDeletingAPollDeletesOptionsParticipantsVotesAndAudience(): void
    {
        $member = $this->makePollMember();
        $poll = $this->makePoll();
        $optionId = $this->optionId($poll, 0);
        Capsule::table('poll_participants')->insert(['poll_id' => $poll->id, 'user_id' => $member->id]);
        PollVote::create(['poll_id' => $poll->id, 'option_id' => $optionId, 'user_id' => $member->id]);

        $poll->delete();

        $this->assertSame(0, PollOption::where('poll_id', $poll->id)->count());
        $this->assertSame(0, Capsule::table('poll_participants')->where('poll_id', $poll->id)->count());
        $this->assertSame(0, PollVote::where('poll_id', $poll->id)->count());
        $this->assertSame(0, AudienceFilter::where('poll_id', $poll->id)->count());
    }

    public function testDeletingAMemberKeepsTheVoteButForgetsThePerson(): void
    {
        $member = $this->makePollMember();
        $poll = $this->makePoll();
        Capsule::table('poll_participants')->insert(['poll_id' => $poll->id, 'user_id' => $member->id]);
        $vote = PollVote::create(['poll_id' => $poll->id, 'option_id' => $this->optionId($poll, 0), 'user_id' => $member->id]);

        $member->delete();

        $this->assertNull($vote->fresh()?->user_id);
        $this->assertNotNull($vote->fresh());
        $this->assertSame(0, Capsule::table('poll_participants')->where('poll_id', $poll->id)->count());
    }

    public function testAMemberChoosesAnOptionAtMostOnce(): void
    {
        $member = $this->makePollMember();
        $poll = $this->makePoll();
        $optionId = $this->optionId($poll, 0);
        PollVote::create(['poll_id' => $poll->id, 'option_id' => $optionId, 'user_id' => $member->id]);

        $this->expectException(QueryException::class);
        PollVote::create(['poll_id' => $poll->id, 'option_id' => $optionId, 'user_id' => $member->id]);
    }

    public function testAMemberParticipatesAtMostOnce(): void
    {
        $member = $this->makePollMember();
        $poll = $this->makePoll();
        Capsule::table('poll_participants')->insert(['poll_id' => $poll->id, 'user_id' => $member->id]);

        $this->expectException(QueryException::class);
        Capsule::table('poll_participants')->insert(['poll_id' => $poll->id, 'user_id' => $member->id]);
    }

    public function testOptionsComeInSortOrderAndClosedFollowsTheDeadline(): void
    {
        $poll = $this->makePoll(['B', 'A'], closesAt: Carbon::now()->subMinute());

        $this->assertSame(['B', 'A'], $poll->options()->pluck('label')->all());
        $this->assertTrue($poll->isClosed());
        $this->assertFalse($this->makePoll(closesAt: Carbon::now()->addMinute())->isClosed());
    }

    public function testAnAudienceFilterWithAPollAndAnEventIsRejected(): void
    {
        $poll = $this->makePoll(audience: null);
        $eventId = Capsule::table('events')->insertGetId([
            'title' => 'Probe',
            'starts_at' => '2030-01-01 19:00:00',
            'ends_at' => '2030-01-01 21:00:00',
            'type' => 'Probe',
        ]);

        $this->expectException(QueryException::class);
        Capsule::table('audience_filters')->insert([
            'created_at' => date('Y-m-d H:i:s'),
            'poll_id' => $poll->id,
            'event_id' => $eventId,
        ]);
    }

    public function testAnAudienceFilterOwnedOnlyByAPollIsAccepted(): void
    {
        $poll = $this->makePoll(audience: null);

        Capsule::table('audience_filters')->insert(['created_at' => date('Y-m-d H:i:s'), 'poll_id' => $poll->id]);

        $this->assertSame(1, AudienceFilter::where('poll_id', $poll->id)->count());
    }

    /** @return list<string> */
    private function columns(string $table): array
    {
        return array_map(
            static fn (object $row): string => (string) $row->COLUMN_NAME,
            Capsule::connection()->select(
                'SELECT COLUMN_NAME FROM information_schema.COLUMNS
                 WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? ORDER BY ORDINAL_POSITION',
                [$table]
            )
        );
    }
}
