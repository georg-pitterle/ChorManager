<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Controllers\SponsoringContactController;
use App\Models\Sponsor;
use App\Models\SponsoringContact;
use App\Models\Sponsorship;
use App\Models\User;
use App\Policies\SponsoringPolicy;
use App\Util\PasswordHasher;
use App\Util\SponsorshipStatus;
use Illuminate\Database\Capsule\Manager as Capsule;
use PHPUnit\Framework\TestCase;
use Psr\Http\Message\ResponseInterface;
use Slim\Views\Twig;
use Tests\Unit\Bootstrap;

/**
 * Die Seite "Kontakte" zeigt alle protokollierten Kontakte, nicht nur die
 * letzten fünf der Übersicht - und zwar genau die, die diese Person auch dort
 * sähe: mit dem Vollrecht alle, sonst die eigenen.
 *
 * Kontakte ohne Anfrage sind als solche markiert; so findet die Verwaltung,
 * was versehentlich ohne Anfrage protokolliert wurde.
 */
final class SponsoringContactOverviewFeatureTest extends TestCase
{
    use TestHttpHelpers;

    private User $member;
    private User $other;
    private Sponsor $sponsor;
    private SponsoringContact $mine;
    private SponsoringContact $theirs;

    protected function setUp(): void
    {
        parent::setUp();
        Bootstrap::setupTestDatabase();
        Capsule::connection()->beginTransaction();
        $_SESSION = [];

        $this->member = $this->person();
        $this->other = $this->person();
        $this->sponsor = Sponsor::create(['name' => 'Kontaktliste ' . bin2hex(random_bytes(4))]);
        $request = Sponsorship::create([
            'sponsor_id' => $this->sponsor->id,
            'amount' => '0.00',
            'status' => SponsorshipStatus::REQUESTED,
            'created_by_user_id' => $this->other->id,
        ]);
        $this->mine = $this->contact((int) $this->member->id, null, 'Eigener Anruf ohne Anfrage');
        $this->theirs = $this->contact((int) $this->other->id, (int) $request->id, 'Fremder Anruf');
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

    public function testTheManagerSeesEveryContactWithItsSummary(): void
    {
        $_SESSION = ['user_id' => (int) $this->member->id, 'can_manage_sponsoring' => true];

        $rows = $this->rows();

        $this->assertSame('Eigener Anruf ohne Anfrage', $rows[(int) $this->mine->id]['summary']);
        $this->assertSame('Fremder Anruf', $rows[(int) $this->theirs->id]['summary']);
    }

    public function testAContributorSeesOnlyTheirOwnContacts(): void
    {
        $_SESSION = ['user_id' => (int) $this->member->id, 'can_create_own_sponsorships' => true];

        $rows = $this->rows();

        $this->assertArrayHasKey((int) $this->mine->id, $rows);
        $this->assertArrayNotHasKey((int) $this->theirs->id, $rows);
    }

    public function testAContactWithoutRequestIsMarked(): void
    {
        $_SESSION = ['user_id' => (int) $this->member->id, 'can_manage_sponsoring' => true];

        $rows = $this->rows();

        $this->assertFalse($rows[(int) $this->mine->id]['has_request']);
        $this->assertTrue($rows[(int) $this->theirs->id]['has_request']);
    }

    public function testSomeoneWithoutSponsoringRightIsRefused(): void
    {
        $_SESSION = ['user_id' => (int) $this->member->id];

        $response = (new SponsoringContactController($this->createStub(Twig::class), new SponsoringPolicy($_SESSION)))
            ->index($this->makeRequest('GET', '/sponsoring/contacts'), $this->makeResponse());

        $this->assertSame(403, $response->getStatusCode());
    }

    /**
     * @return array<int, array<string, mixed>> Zeilen dieses Tests, nach Kontakt-Id
     */
    private function rows(): array
    {
        $captured = [];
        $twig = $this->createStub(Twig::class);
        $twig->method('render')->willReturnCallback(
            function ($response, string $template, array $data) use (&$captured): ResponseInterface {
                $captured = $data;
                return $response;
            }
        );

        (new SponsoringContactController($twig, new SponsoringPolicy($_SESSION)))
            ->index($this->makeRequest('GET', '/sponsoring/contacts'), $this->makeResponse());

        $rows = [];
        foreach ($captured['contacts'] ?? [] as $row) {
            if ($row['sponsor_name'] === $this->sponsor->name) {
                $rows[(int) $row['id']] = $row;
            }
        }

        return $rows;
    }

    private function contact(int $userId, ?int $sponsorshipId, string $summary): SponsoringContact
    {
        return SponsoringContact::create([
            'sponsor_id' => $this->sponsor->id,
            'sponsorship_id' => $sponsorshipId,
            'user_id' => $userId,
            'contact_date' => date('Y-m-d'),
            'type' => 'call',
            'summary' => $summary,
            'follow_up_done' => 0,
        ]);
    }

    private function person(): User
    {
        return User::create([
            'first_name' => 'Kontakt',
            'last_name' => 'Liste',
            'email' => 'contact-list-' . bin2hex(random_bytes(6)) . '@example.test',
            'password' => PasswordHasher::hash('x'),
            'is_active' => 1,
        ]);
    }
}
