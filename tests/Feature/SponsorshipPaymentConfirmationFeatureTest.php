<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Controllers\SponsoringDashboardController;
use App\Controllers\SponsorshipController;
use App\Models\Sponsor;
use App\Models\Sponsorship;
use App\Models\User;
use App\Policies\SponsoringPolicy;
use App\Services\NameFormatterService;
use App\Util\PasswordHasher;
use App\Util\SponsorshipStatus;
use Carbon\Carbon;
use Illuminate\Database\Capsule\Manager as Capsule;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Psr\Http\Message\ResponseInterface;
use Slim\Views\Twig;
use Tests\Unit\Bootstrap;

/**
 * Eine eingegangene Zahlung bestätigt, wer das Sponsoring verwaltet, wer die
 * Finanzen führt oder wer die Anfrage selbst ändern darf - direkt aus der Liste
 * "Zahlung offen", ohne den Dialog der Anfrage.
 *
 * Der Kassier hat in der Regel nur das Finanzrecht. Er sieht deshalb eine
 * eigene Seite mit genau dieser Liste und sonst nichts vom Sponsoring.
 */
final class SponsorshipPaymentConfirmationFeatureTest extends TestCase
{
    use TestHttpHelpers;
    use BuildsControllers;

    private Sponsor $sponsor;
    private Sponsorship $commitment;
    private User $author;

    protected function setUp(): void
    {
        parent::setUp();
        Bootstrap::setupTestDatabase();
        Capsule::connection()->beginTransaction();
        Carbon::setTestNow('2026-11-15 10:00:00');

        $this->author = $this->person();
        $this->sponsor = Sponsor::create(['name' => 'Zahlungstest ' . bin2hex(random_bytes(4))]);
        $this->commitment = Sponsorship::create([
            'sponsor_id' => $this->sponsor->id,
            'amount' => '250.00',
            'status' => SponsorshipStatus::ACCEPTED,
            'created_by_user_id' => $this->author->id,
        ]);
        $_SESSION = [];
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

    /**
     * @return array<string, array{array<string, bool>, bool}>
     */
    public static function rights(): array
    {
        return [
            'Sponsoring verwalten' => [['can_manage_sponsoring' => true], true],
            'Finanzen schreiben' => [['can_manage_finances' => true], true],
            'nur Finanzen lesen' => [['can_read_finances' => true], false],
            'fremdes beitragendes Mitglied' => [['can_create_own_sponsorships' => true], false],
            'ohne Recht' => [[], false],
        ];
    }

    /**
     * @param array<string, bool> $rights
     */
    #[DataProvider('rights')]
    public function testWhoMayConfirmAPayment(array $rights, bool $allowed): void
    {
        $_SESSION = $rights + ['user_id' => (int) $this->person()->id];

        $response = $this->confirm('2026-11-10');

        $this->assertSame($allowed ? 302 : 403, $response->getStatusCode());
        $this->assertSame($allowed ? '2026-11-10' : null, $this->commitment->fresh()->paid_at?->format('Y-m-d'));
    }

    public function testTheAuthorConfirmsTheirOwnCommitment(): void
    {
        $_SESSION = ['user_id' => (int) $this->author->id, 'can_create_own_sponsorships' => true];

        $this->confirm('2026-11-12');

        $this->assertSame('2026-11-12', $this->commitment->fresh()->paid_at?->format('Y-m-d'));
    }

    public function testWithoutADateTodayIsStored(): void
    {
        $_SESSION = ['can_manage_finances' => true];

        $this->confirm('');

        $this->assertSame('2026-11-15', $this->commitment->fresh()->paid_at?->format('Y-m-d'));
    }

    public function testAnInvalidDateIsRefused(): void
    {
        $_SESSION = ['can_manage_finances' => true];

        $this->confirm('31.02.2026');

        $this->assertSame(SponsorshipController::PAID_AT_ERROR, $_SESSION['error'] ?? null);
        $this->assertNull($this->commitment->fresh()->paid_at);
    }

    /**
     * Bezahlt wird eine Zusage. Eine offene Anfrage mit Zahlungsdatum stünde
     * als bezahlt da, ohne je zugesagt zu sein.
     */
    public function testOnlyACommitmentCanBePaid(): void
    {
        $_SESSION = ['can_manage_finances' => true];
        $this->commitment->update(['status' => SponsorshipStatus::REQUESTED]);

        $this->confirm('2026-11-10');

        $this->assertSame(SponsorshipController::NOT_ACCEPTED_ERROR, $_SESSION['error'] ?? null);
        $this->assertNull($this->commitment->fresh()->paid_at);
    }

    public function testTheTreasurerGoesBackToThePaymentsPage(): void
    {
        $_SESSION = ['can_manage_finances' => true];
        $this->assertRedirect($this->confirm('2026-11-10'), '/sponsoring/payments');

        $this->commitment->update(['paid_at' => null]);
        $_SESSION = ['can_manage_sponsoring' => true];
        $this->assertRedirect($this->confirm('2026-11-10'), '/sponsoring');
    }

    public function testThePaymentsPageListsOpenCommitmentsForTheTreasurer(): void
    {
        $_SESSION = ['can_manage_finances' => true];

        $this->assertContains((int) $this->commitment->id, $this->paymentPageIds());

        $this->commitment->update(['paid_at' => '2026-11-10']);
        $this->assertNotContains((int) $this->commitment->id, $this->paymentPageIds());
    }

    public function testThePaymentsPageIsClosedToContributors(): void
    {
        $_SESSION = ['user_id' => (int) $this->author->id, 'can_create_own_sponsorships' => true];

        $response = $this->dashboard($this->createStub(Twig::class))
            ->payments($this->makeRequest('GET', '/sponsoring/payments'), $this->makeResponse());

        $this->assertSame(403, $response->getStatusCode());
    }

    private function confirm(string $date): ResponseInterface
    {
        return $this->sponsorshipController(['attachments' => $this->attachmentService()])->confirmPayment(
            $this->makeRequest('POST', '/sponsoring/sponsorships/' . $this->commitment->id . '/paid', ['paid_at' => $date]),
            $this->makeResponse(),
            ['id' => (string) $this->commitment->id]
        );
    }

    /**
     * @return list<int>
     */
    private function paymentPageIds(): array
    {
        $captured = [];
        $twig = $this->createStub(Twig::class);
        $twig->method('render')->willReturnCallback(
            function ($response, string $template, array $data) use (&$captured): ResponseInterface {
                $captured = $data;
                return $response;
            }
        );

        $this->dashboard($twig)->payments($this->makeRequest('GET', '/sponsoring/payments'), $this->makeResponse());

        return array_column($captured['unpaid'] ?? [], 'id');
    }

    private function dashboard(Twig $twig): SponsoringDashboardController
    {
        return new SponsoringDashboardController($twig, new NameFormatterService(), new SponsoringPolicy($_SESSION));
    }

    private function person(): User
    {
        return User::create([
            'first_name' => 'Zahlung',
            'last_name' => 'Testperson',
            'email' => 'payment-' . bin2hex(random_bytes(6)) . '@example.test',
            'password' => PasswordHasher::hash('x'),
            'is_active' => 1,
        ]);
    }
}
