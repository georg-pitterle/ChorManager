<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Controllers\UserController;
use App\Models\InvitationToken;
use App\Models\User;
use App\Persistence\ProjectPersistence;
use App\Persistence\UserPersistence;
use App\Policies\UserEditPolicy;
use App\Queries\UserQuery;
use App\Services\MailQueueService;
use App\Services\NameFormatterService;
use Illuminate\Database\Capsule\Manager as Capsule;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use Slim\Views\Twig;
use Tests\Unit\Bootstrap;

/**
 * Einladung an ein archiviertes Mitglied.
 *
 * Die Einladung setzt das Passwort des Zielkontos neu. Für ein archiviertes
 * Konto führt das ins Leere - anmelden kann sich damit niemand mehr
 * (UserQuery::findByEmail() filtert auf is_active), und einlösen lässt sich der
 * Link seit dem Archiv-Riegel in PasswordResetController auch nicht. Die Mail
 * ginge trotzdem an jemanden hinaus, der nicht mehr im Chor ist. Also gar nicht
 * erst verschicken und stattdessen sagen, was zu tun wäre.
 */
final class InviteArchivedMemberFeatureTest extends TestCase
{
    use TestHttpHelpers;

    protected function setUp(): void
    {
        parent::setUp();
        Bootstrap::setupTestDatabase();
        Capsule::connection()->beginTransaction();

        $_SESSION = ['user_id' => 1, 'can_manage_users' => true, 'role_level' => 100];
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

    public function testInvitingAnArchivedMemberIsRefusedAndCreatesNoToken(): void
    {
        $user = $this->createMember(isActive: 0);

        $response = $this->invite((int) $user->id);
        $payload = (array) json_decode((string) $response->getBody(), true);

        $this->assertSame(409, $response->getStatusCode());
        $this->assertFalse($payload['success']);
        $this->assertStringContainsString('archiviert', $payload['message']);
        $this->assertStringContainsString('wiederherstellen', $payload['message']);

        $this->assertFalse(
            InvitationToken::where('user_id', $user->id)->exists(),
            'Für ein archiviertes Konto darf keine Einladung entstehen.'
        );
    }

    public function testInvitingAnActiveMemberStillGetsPastTheArchiveCheck(): void
    {
        $user = $this->createMember(isActive: 1);

        // Der Mailversand ist ausgestubbt; entscheidend ist nur, dass die
        // Archiv-Prüfung ein aktives Mitglied nicht abweist.
        $response = $this->invite((int) $user->id);

        $this->assertNotSame(409, $response->getStatusCode());
    }

    private function createMember(int $isActive): User
    {
        return User::create([
            'first_name' => 'Eingeladene',
            'last_name' => 'Sängerin',
            'email' => 'einladung-' . bin2hex(random_bytes(6)) . '@example.test',
            'password' => password_hash('irrelevant', PASSWORD_DEFAULT),
            'is_active' => $isActive,
        ]);
    }

    private function invite(int $userId): \Psr\Http\Message\ResponseInterface
    {
        $controller = new UserController(
            $this->createStub(Twig::class),
            new UserQuery(new NameFormatterService()),
            new UserPersistence(new NullLogger()),
            $this->createStub(ProjectPersistence::class),
            $this->createStub(MailQueueService::class),
            new NullLogger(),
            new UserEditPolicy()
        );

        return $controller->invite(
            $this->makeRequest('POST', '/users/' . $userId . '/invite'),
            $this->makeResponse(),
            ['id' => (string) $userId]
        );
    }
}
