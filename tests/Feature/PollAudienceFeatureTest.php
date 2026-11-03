<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Services\Audience\InvalidAudienceFilterException;
use App\Services\PollAudienceService;
use Illuminate\Database\Capsule\Manager as Capsule;
use PHPUnit\Framework\TestCase;
use Tests\Unit\Bootstrap;

/**
 * Zielgruppe einer Umfrage: live ausgewertet wie bei Terminen.
 */
final class PollAudienceFeatureTest extends TestCase
{
    use PollFixtures;

    private PollAudienceService $audience;

    protected function setUp(): void
    {
        parent::setUp();
        Bootstrap::setupTestDatabase();
        Capsule::connection()->beginTransaction();
        $this->audience = new PollAudienceService();
    }

    protected function tearDown(): void
    {
        $connection = Capsule::connection();
        if ($connection->transactionLevel() > 0) {
            $connection->rollBack();
        }

        parent::tearDown();
    }

    public function testAVoiceGroupAudienceReachesOnlyItsActiveMembers(): void
    {
        $soprano = $this->makePollMember('Sopran');
        $inactive = $this->makePollMember('Inaktiv', false);
        $bass = $this->makePollMember('Bass');
        $group = $this->makePollVoiceGroup($soprano, $inactive);
        $poll = $this->makePoll(audience: ['voice_group' => [(int) $group->id]]);

        $this->assertTrue($this->audience->isMember($poll, (int) $soprano->id));
        $this->assertFalse($this->audience->isMember($poll, (int) $inactive->id));
        $this->assertFalse($this->audience->isMember($poll, (int) $bass->id));
        $this->assertSame(1, $this->audience->memberCount($poll));
    }

    public function testVisiblePollIdsFollowTheAudienceLive(): void
    {
        $member = $this->makePollMember();
        $other = $this->makePollMember();
        $mine = $this->makePoll(audience: ['user' => [(int) $member->id]]);
        $foreign = $this->makePoll(audience: ['user' => [(int) $other->id]]);

        $visible = $this->audience->visiblePollIds((int) $member->id);
        $this->assertContains((int) $mine->id, $visible);
        $this->assertNotContains((int) $foreign->id, $visible);

        $this->audience->setAudience($foreign, [['user' => [(int) $member->id]]]);
        $this->assertContains((int) $foreign->id, $this->audience->visiblePollIds((int) $member->id));
    }

    public function testAnEmptyAudienceFormIsRejected(): void
    {
        $this->expectException(InvalidAudienceFilterException::class);
        $this->audience->readRows([]);
    }

    public function testEveryoneIsARowWithTheCheckbox(): void
    {
        $this->assertSame([[]], $this->audience->readRows(['audience' => [['all' => '1']]]));
    }
}
