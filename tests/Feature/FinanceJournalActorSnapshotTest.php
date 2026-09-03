<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\Finance;
use App\Models\FinanceAccount;
use App\Models\FinanceRevision;
use App\Models\User;
use App\Services\FinanceJournalService;
use Illuminate\Database\Capsule\Manager as Capsule;
use PHPUnit\Framework\TestCase;
use Tests\Unit\Bootstrap;

/**
 * Das Prüfjournal muss auch dann noch sagen können, wer gehandelt hat, wenn das
 * Mitglied den Chor längst verlassen hat und gelöscht wurde (§ 131 BAO).
 *
 * Vor 20260901121000 stand dort nur eine user_id ohne Fremdschlüssel: Nach dem
 * Löschen zeigte der Eintrag "System" - dieselbe Anzeige wie ein Eintrag ganz
 * ohne Person.
 */
final class FinanceJournalActorSnapshotTest extends TestCase
{
    private FinanceJournalService $journal;
    private FinanceAccount $account;

    protected function setUp(): void
    {
        parent::setUp();
        Bootstrap::setupTestDatabase();
        Capsule::connection()->beginTransaction();

        $this->journal = new FinanceJournalService();
        $this->account = FinanceAccount::create([
            'name' => 'Prüfjournal-Konto ' . bin2hex(random_bytes(4)),
            'type' => 'cash',
            'opening_balance' => '0.00',
            'opening_date' => date('Y-m-d'),
            'is_active' => 1,
            'sort_order' => 99,
        ]);
    }

    protected function tearDown(): void
    {
        Capsule::connection()->rollBack();
        parent::tearDown();
    }

    public function testActorSurvivesDeletionOfTheMember(): void
    {
        $user = $this->createUser('Wilhelmine', 'Gschwandtner');
        $booking = $this->createBooking();

        $this->journal->recordCreate($booking, (int) $user->id);

        $user->delete();

        $revision = FinanceRevision::where('finance_id', $booking->id)->firstOrFail();

        $this->assertNull($revision->user, 'Das Mitglied ist weg - der Name darf trotzdem stehen.');
        $this->assertSame(
            ['first_name' => 'Wilhelmine', 'last_name' => 'Gschwandtner'],
            $revision->actor
        );
    }

    public function testEntryWithoutMemberStaysSystem(): void
    {
        $booking = $this->createBooking();

        $this->journal->recordCreate($booking, null);

        $revision = FinanceRevision::where('finance_id', $booking->id)->firstOrFail();

        $this->assertNull(
            $revision->actor,
            'Ohne handelnde Person bleibt es "System" - das darf sich nicht mit gelöschten Mitgliedern vermischen.'
        );
    }

    public function testLockEntryAlsoKeepsTheName(): void
    {
        $user = $this->createUser('Korbinian', 'Größwang');

        $written = $this->journal->recordLockChange(null, date('Y-m-d'), (int) $user->id);
        $this->assertTrue($written);

        $user->delete();

        $revision = FinanceRevision::where('action', FinanceRevision::ACTION_LOCK)
            ->orderBy('id', 'desc')
            ->firstOrFail();

        $this->assertSame(
            ['first_name' => 'Korbinian', 'last_name' => 'Größwang'],
            $revision->actor,
            'Gerade der Buchungsabschluss muss nachvollziehbar bleiben.'
        );
    }

    private function createUser(string $firstName, string $lastName): User
    {
        return User::create([
            'email' => 'journal-' . bin2hex(random_bytes(6)) . '@example.test',
            'password' => password_hash('irrelevant', PASSWORD_DEFAULT),
            'first_name' => $firstName,
            'last_name' => $lastName,
            'is_active' => 1,
        ]);
    }

    private function createBooking(): Finance
    {
        return Finance::create([
            'running_number' => ((int) Finance::max('running_number')) + 1,
            'invoice_date' => date('Y-m-d'),
            'payment_date' => date('Y-m-d'),
            'description' => 'Notenankauf für die Probenarbeit',
            'type' => 'expense',
            'amount' => '42.00',
            'payment_method' => 'cash',
            'finance_account_id' => $this->account->id,
        ]);
    }
}
