<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Models\User;
use PHPUnit\Framework\TestCase;

/**
 * "Einladung offen" in der Mitgliederliste.
 *
 * Anmeldungen werden erst seit Migration 20261017120000 erfasst. Ein leeres
 * last_login_at heißt bei Bestandsmitgliedern deshalb nicht "nie angemeldet".
 * Eine sichere Aussage gibt es nur für Einladungen ab Beginn der Erfassung:
 * Kam seither keine Anmeldung, ist die Einladung offen.
 */
final class UserInvitationPendingTest extends TestCase
{
    private function user(?string $invitationSentAt, ?string $lastLoginAt): User
    {
        $user = new User();
        $user->invitation_sent_at = $invitationSentAt;
        $user->last_login_at = $lastLoginAt;

        return $user;
    }

    public function testAnInvitationWithoutALaterLoginIsPending(): void
    {
        $this->assertTrue($this->user('2026-10-20 10:00:00', null)->invitationPending());
    }

    public function testALoginAfterTheInvitationClosesIt(): void
    {
        $this->assertFalse($this->user('2026-10-20 10:00:00', '2026-10-21 08:00:00')->invitationPending());
    }

    public function testALoginBeforeANewInvitationLeavesTheNewOneOpen(): void
    {
        $this->assertTrue($this->user('2026-10-20 10:00:00', '2026-10-18 08:00:00')->invitationPending());
    }

    public function testInvitationsFromBeforeTheTrackingSayNothing(): void
    {
        // Aus der Warteschlange nachgetragen; ob danach eine Anmeldung kam, weiß niemand.
        $this->assertFalse($this->user('2026-09-01 10:00:00', null)->invitationPending());
    }

    public function testALoginAfterTheLastInvitationMeansTheAppIsInUse(): void
    {
        $this->assertTrue($this->user('2026-10-20 10:00:00', '2026-10-21 08:00:00')->usesApp());
        $this->assertTrue($this->user(null, '2026-10-21 08:00:00')->usesApp());
    }

    public function testANewerInvitationOrNoLoginMeansNotYetInUse(): void
    {
        // Wer neu eingeladen wurde, nachdem er sich zuletzt angemeldet hat, soll
        // die neue Einladung auch bekommen dürfen.
        $this->assertFalse($this->user('2026-10-22 10:00:00', '2026-10-21 08:00:00')->usesApp());
        $this->assertFalse($this->user('2026-10-20 10:00:00', null)->usesApp());
        $this->assertFalse($this->user(null, null)->usesApp());
    }

    public function testWithoutAnInvitationNothingIsPending(): void
    {
        $this->assertFalse($this->user(null, null)->invitationPending());
    }
}
