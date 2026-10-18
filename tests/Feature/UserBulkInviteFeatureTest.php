<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Controllers\UserController;
use App\Models\InvitationToken;
use App\Models\MailQueue;
use App\Models\Role;
use App\Models\User;
use App\Persistence\ProjectPersistence;
use App\Persistence\UserPersistence;
use App\Policies\UserEditPolicy;
use App\Queries\UserQuery;
use App\Services\MailQueueService;
use App\Services\NameFormatterService;
use App\Util\PasswordHasher;
use Illuminate\Database\Capsule\Manager as Capsule;
use PHPUnit\Framework\TestCase;
use Psr\Http\Message\ResponseInterface;
use Psr\Log\NullLogger;
use Slim\Views\Twig;
use Tests\Unit\Bootstrap;

/**
 * Einladung an mehrere Mitglieder auf einmal.
 *
 * Der Filter "Einladung: nicht gesendet" führt geradewegs zu der Frage, wie man
 * diese Personen jetzt einlädt. Bisher hieß die Antwort: jede einzeln öffnen.
 * Die Sammelaktion gilt dieselben Regeln wie die Einzeleinladung - nur mit
 * Mitgliederverwaltung, nicht für archivierte Konten, nicht für Ranghöhere -
 * und überspringt, was sie nicht darf, statt den ganzen Lauf abzubrechen.
 */
final class UserBulkInviteFeatureTest extends TestCase
{
    use TestHttpHelpers;

    /** @var list<int> */
    private array $enqueuedUserIds = [];

    protected function setUp(): void
    {
        parent::setUp();
        Bootstrap::setupTestDatabase();
        Capsule::connection()->beginTransaction();

        $_SESSION = ['user_id' => 1, 'can_manage_users' => true, 'role_level' => 50];
        $this->enqueuedUserIds = [];
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

    public function testAManagerInvitesEverySelectedActiveMember(): void
    {
        $first = $this->createMember();
        $second = $this->createMember();

        $response = $this->bulkInvite([(int) $first->id, (int) $second->id]);

        $this->assertRedirect($response, '/users');
        $this->assertSame([(int) $first->id, (int) $second->id], $this->enqueuedUserIds);
        $this->assertTrue(InvitationToken::where('user_id', $first->id)->exists());
        $this->assertTrue(InvitationToken::where('user_id', $second->id)->exists());
        $this->assertSame('Einladung an 2 Mitglieder verschickt.', $_SESSION['success'] ?? null);
    }

    public function testArchivedOutrankingAndOwnAccountsAreSkippedAndCounted(): void
    {
        $invitable = $this->createMember();
        $archived = $this->createMember(isActive: 0);
        $outranking = $this->createMember();
        $role = Role::create(['name' => 'Ranghöher ' . bin2hex(random_bytes(3)), 'hierarchy_level' => 90]);
        $outranking->roles()->attach($role->id);

        $response = $this->bulkInvite([
            (int) $invitable->id,
            (int) $archived->id,
            (int) $outranking->id,
            1,
        ]);

        $this->assertRedirect($response, '/users');
        $this->assertSame([(int) $invitable->id], $this->enqueuedUserIds);
        $this->assertFalse(InvitationToken::where('user_id', $archived->id)->exists());
        $this->assertFalse(InvitationToken::where('user_id', $outranking->id)->exists());
        $this->assertSame(
            'Einladung an 1 Mitglied verschickt. Übersprungen: Sammel Einladung (archiviert), '
            . 'Sammel Einladung (keine Berechtigung), dein eigenes Konto.',
            $_SESSION['success'] ?? null
        );
    }

    public function testMembersWhoAlreadyUseTheAppAreNotMailedAgain(): void
    {
        // "Alle auswählen" und "Einladung senden" schickte bisher jedem aktiven
        // Mitglied einen Link zum Passwortsetzen - auch denen, die sich täglich
        // anmelden.
        $newcomer = $this->createMember();
        $regular = $this->createMember();
        $regular->last_login_at = '2026-10-17 20:00:00';
        $regular->save();

        $this->bulkInvite([(int) $newcomer->id, (int) $regular->id]);

        $this->assertSame([(int) $newcomer->id], $this->enqueuedUserIds);
        $this->assertSame(
            'Einladung an 1 Mitglied verschickt. Übersprungen: Sammel Einladung (nutzt die App schon).',
            $_SESSION['success'] ?? null
        );
    }

    public function testALongSkipListIsCutAfterFiveNames(): void
    {
        $archivedIds = [];
        for ($i = 0; $i < 7; $i++) {
            $archivedIds[] = (int) $this->createMember(isActive: 0)->id;
        }

        $this->bulkInvite($archivedIds);

        $this->assertStringEndsWith(
            'Sammel Einladung (archiviert) und 2 weitere.',
            (string) ($_SESSION['success'] ?? '')
        );
        $this->assertSame(5, substr_count((string) ($_SESSION['success'] ?? ''), '(archiviert)'));
    }

    public function testWithoutMemberManagementNothingIsSent(): void
    {
        $_SESSION['can_manage_users'] = false;
        $member = $this->createMember();

        $response = $this->bulkInvite([(int) $member->id]);

        $this->assertRedirect($response, '/users');
        $this->assertSame([], $this->enqueuedUserIds);
        $this->assertFalse(InvitationToken::where('user_id', $member->id)->exists());
        $this->assertSame('Du hast keine Berechtigung, Einladungen zu verschicken.', $_SESSION['error'] ?? null);
    }

    public function testAnEmptySelectionIsRefused(): void
    {
        $response = $this->bulkInvite([]);

        $this->assertRedirect($response, '/users');
        $this->assertSame([], $this->enqueuedUserIds);
        $this->assertSame('Keine Mitglieder ausgewählt.', $_SESSION['error'] ?? null);
    }

    public function testTheRouteIsRegisteredInsideTheUserGroup(): void
    {
        $routes = (string) file_get_contents(dirname(__DIR__, 2) . '/src/Routes.php');

        $this->assertStringContainsString(
            "\$userGroup->post('/bulk-invite', [UserController::class, 'bulkInvite']);",
            $routes
        );
    }

    private function createMember(int $isActive = 1): User
    {
        return User::create([
            'first_name' => 'Sammel',
            'last_name' => 'Einladung',
            'email' => 'bulk-invite-' . bin2hex(random_bytes(6)) . '@example.test',
            'password' => PasswordHasher::hash('irrelevant'),
            'is_active' => $isActive,
        ]);
    }

    /**
     * @param list<int> $userIds
     */
    private function bulkInvite(array $userIds): ResponseInterface
    {
        $mailQueue = $this->createStub(MailQueueService::class);
        $mailQueue->method('enqueueInvitationMail')->willReturnCallback(
            function (string $recipientEmail, string $subject, string $bodyHtml, int $userId): MailQueue {
                $this->enqueuedUserIds[] = $userId;

                return new MailQueue();
            }
        );

        $controller = new UserController(
            $this->createStub(Twig::class),
            new UserQuery(new NameFormatterService()),
            new UserPersistence(new NullLogger()),
            $this->createStub(ProjectPersistence::class),
            $mailQueue,
            new NullLogger(),
            new UserEditPolicy($_SESSION)
        );

        return $controller->bulkInvite(
            $this->makeRequest('POST', '/users/bulk-invite', ['user_ids' => implode(',', $userIds)]),
            $this->makeResponse()
        );
    }
}
