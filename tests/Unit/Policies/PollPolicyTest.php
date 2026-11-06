<?php

declare(strict_types=1);

namespace Tests\Unit\Policies;

use App\Policies\PollPolicy;
use PHPUnit\Framework\TestCase;

/**
 * Wer eine Umfrage sieht, wer abstimmt und wer das Ergebnis sieht.
 *
 * Ob jemand zur Zielgruppe gehört, kommt von außen (PollAudienceService);
 * die Policy verknüpft es nur mit dem Recht und dem Stand der Umfrage.
 */
final class PollPolicyTest extends TestCase
{
    public function testTheAudienceSeesAndVotes(): void
    {
        $policy = new PollPolicy(['user_id' => 7]);

        $this->assertTrue($policy->canView(true));
        $this->assertTrue($policy->canVote(inAudience: true, isClosed: false));
        $this->assertFalse($policy->canView(false));
        $this->assertFalse($policy->canVote(inAudience: false, isClosed: false));
        $this->assertFalse($policy->canManage());
    }

    public function testManagingIsNoRightToVote(): void
    {
        $policy = new PollPolicy(['user_id' => 7, 'can_manage_polls' => 1]);

        $this->assertTrue($policy->canManage());
        $this->assertTrue($policy->canView(false));
        $this->assertFalse($policy->canVote(inAudience: false, isClosed: false));
    }

    /**
     * Die Frist gehört zur Abstimmberechtigung, nicht an die Aufrufstelle.
     *
     * Sie stand zuvor als `&& !$isClosed` im PollController, obwohl
     * canSeeResults() den Stand der Umfrage schon als Parameter nahm. Damit
     * beantwortete die Policy nur die halbe Frage: Eine zweite Aufrufstelle -
     * eine neue Route, ein weiteres Template - hätte mit canVote($inAudience)
     * einen Stimmzettel für eine beendete Umfrage angeboten, und aufgefallen
     * wäre es erst an der Abweisung in PollVoteService. Mit der Frist in der
     * Signatur kann die Prüfung die Frist nicht mehr übergehen.
     */
    public function testNobodyVotesAfterTheDeadline(): void
    {
        $member = new PollPolicy(['user_id' => 7]);
        $manager = new PollPolicy(['user_id' => 7, 'can_manage_polls' => true]);

        $this->assertFalse($member->canVote(inAudience: true, isClosed: true));
        $this->assertFalse($manager->canVote(inAudience: true, isClosed: true));
    }

    public function testResultsAfterOwnVoteOrAfterTheDeadline(): void
    {
        $member = new PollPolicy(['user_id' => 7]);

        $this->assertFalse($member->canSeeResults(inAudience: true, hasVoted: false, isClosed: false));
        $this->assertTrue($member->canSeeResults(inAudience: true, hasVoted: true, isClosed: false));
        $this->assertTrue($member->canSeeResults(inAudience: true, hasVoted: false, isClosed: true));
        $this->assertFalse($member->canSeeResults(inAudience: false, hasVoted: false, isClosed: true));
    }

    public function testManagersAlwaysSeeResults(): void
    {
        $manager = new PollPolicy(['user_id' => 7, 'can_manage_polls' => true]);

        $this->assertTrue($manager->canSeeResults(inAudience: false, hasVoted: false, isClosed: false));
    }

    public function testTheCurrentUser(): void
    {
        $this->assertSame(7, (new PollPolicy(['user_id' => '7']))->currentUserId());
        $this->assertSame(0, (new PollPolicy([]))->currentUserId());
    }
}
