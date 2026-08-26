<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Controllers\UserController;
use App\Models\Role;
use App\Models\User;
use App\Persistence\ProjectPersistence;
use App\Persistence\UserPersistence;
use App\Policies\UserEditPolicy;
use App\Queries\UserQuery;
use App\Services\MailQueueService;
use Illuminate\Support\Collection;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use Slim\Views\Twig;
use Tests\Unit\Bootstrap;

class UserProjectScopeFeatureTest extends TestCase
{
    use TestHttpHelpers;

    /** Rein synthetische Id des Ziel-Mitglieds, nie geladen aus der DB. */
    private int $targetUserId = 0;
    private string $targetEmail = '';

    /** Reale Rolle bei hierarchy_level 10, benoetigt fuer die Rollenkappung in update(). */
    private int $roleId = 0;

    protected function setUp(): void
    {
        parent::setUp();
        Bootstrap::setupTestDatabase();
        Bootstrap::getCapsule()?->connection()->beginTransaction();
        $_SESSION = [];

        $suffix = bin2hex(random_bytes(4));
        $this->targetUserId = random_int(500000, 999999);
        $this->targetEmail = 'target' . $suffix . '@example.test';

        $this->roleId = (int) Role::create([
            'name' => 'Rolle ' . $suffix,
            'hierarchy_level' => 10,
        ])->id;
    }

    protected function tearDown(): void
    {
        $connection = Bootstrap::getCapsule()?->connection();
        if ($connection !== null && $connection->transactionLevel() > 0) {
            $connection->rollBack();
        }

        $_SESSION = [];
        parent::tearDown();
    }

    /**
     * can_manage_project_members wirkt projektuebergreifend: der Verwalter darf
     * auch Projekte zuweisen, in denen er selbst nicht Mitglied ist. Andernfalls
     * bliebe ein frisch angelegtes Projekt ohne Mitglieder unerreichbar.
     *
     * Geschrieben wird dabei ausschliesslich die Projektzuordnung. Name, E-Mail,
     * Rollen und Stimmgruppen bleiben unangetastet, auch wenn das Formular sie
     * mitschickt - eine fremde E-Mail-Adresse plus Passwort-Reset waere sonst ein
     * Uebernahmepfad auf das Zielkonto.
     */
    public function testUpdateAssignsEveryProjectForProjectMemberManagers(): void
    {
        $_SESSION['user_id'] = 100;
        $_SESSION['can_edit_users'] = false;
        $_SESSION['can_manage_users'] = false;
        $_SESSION['can_manage_project_members'] = true;
        $_SESSION['role_level'] = 50;
        $_SESSION['voice_group_ids'] = [];

        $targetUser = new User();
        $targetUser->id = $this->targetUserId;
        $targetUser->first_name = 'Target';
        $targetUser->last_name = 'User';
        $targetUser->email = $this->targetEmail;
        $targetUser->setRelation('roles', new Collection([]));
        $targetUser->setRelation('voiceGroups', new Collection([]));

        $twig = $this->createStub(Twig::class);

        $userQuery = $this->createMock(UserQuery::class);
        $userQuery->expects($this->once())
            ->method('findById')
            ->with($this->targetUserId)
            ->willReturn($targetUser);


        $userPersistence = $this->createMock(UserPersistence::class);
        $userPersistence->expects($this->never())->method('save');
        $userPersistence->expects($this->never())->method('syncRoles');
        $userPersistence->expects($this->never())->method('syncVoiceGroups');

        $projectPersistence = $this->createMock(ProjectPersistence::class);
        $projectPersistence->expects($this->once())
            ->method('setUserProjects')
            ->with($this->targetUserId, [2, 3, 4]);

        $mailQueueService = $this->createStub(MailQueueService::class);
        $logger = $this->createStub(LoggerInterface::class);

        $controller = new UserController(
            $twig,
            $userQuery,
            $userPersistence,
            $projectPersistence,
            $mailQueueService,
            $logger,
            new UserEditPolicy()
        );

        $request = $this->makeRequest('POST', '/users/' . $this->targetUserId, [
            'first_name' => 'Gekapert',
            'last_name' => 'Uebernommen',
            'email' => 'angreifer@example.test',
            'password' => '',
            'roles' => [$this->roleId],
            'voice_groups' => [],
            'sub_voices' => [],
            'projects' => [2, 3, 4],
        ]);
        $response = $this->makeResponse();

        $result = $controller->update($request, $response, ['id' => (string) $this->targetUserId]);

        $this->assertRedirect($result, '/users');
        $this->assertSame('Projektzuordnung erfolgreich aktualisiert.', $_SESSION['success'] ?? null);
        $this->assertSame('Target', $targetUser->first_name);
        $this->assertSame($this->targetEmail, $targetUser->email);
    }

    public function testUpdateDoesNotFilterProjectsForGlobalEditors(): void
    {
        $_SESSION['user_id'] = 1;
        $_SESSION['can_edit_users'] = true;
        $_SESSION['can_manage_users'] = true;
        $_SESSION['can_manage_project_members'] = true;
        $_SESSION['role_level'] = 100;
        $_SESSION['voice_group_ids'] = [];

        $targetUser = new User();
        $targetUser->id = $this->targetUserId;
        $targetUser->first_name = 'Target';
        $targetUser->last_name = 'User';
        $targetUser->email = $this->targetEmail;
        $targetUser->setRelation('roles', new Collection([]));
        $targetUser->setRelation('voiceGroups', new Collection([]));

        $twig = $this->createStub(Twig::class);

        $userQuery = $this->createMock(UserQuery::class);
        $userQuery->expects($this->once())
            ->method('findById')
            ->with($this->targetUserId)
            ->willReturn($targetUser);


        $userPersistence = $this->createMock(UserPersistence::class);
        $userPersistence->expects($this->once())
            ->method('save')
            ->with($targetUser);
        $userPersistence->expects($this->once())
            ->method('syncRoles')
            ->with($targetUser, [$this->roleId]);
        $userPersistence->expects($this->once())
            ->method('syncVoiceGroups')
            ->with($targetUser, []);

        $projectPersistence = $this->createMock(ProjectPersistence::class);
        $projectPersistence->expects($this->once())
            ->method('setUserProjects')
            ->with($this->targetUserId, [2, 3, 4]);

        $mailQueueService = $this->createStub(MailQueueService::class);
        $logger = $this->createStub(LoggerInterface::class);

        $controller = new UserController(
            $twig,
            $userQuery,
            $userPersistence,
            $projectPersistence,
            $mailQueueService,
            $logger,
            new UserEditPolicy()
        );

        $request = $this->makeRequest('POST', '/users/' . $this->targetUserId, [
            'first_name' => 'Target',
            'last_name' => 'User',
            'email' => $this->targetEmail,
            'password' => '',
            'roles' => [$this->roleId],
            'voice_groups' => [],
            'sub_voices' => [],
            'projects' => [2, 3, 4],
        ]);
        $response = $this->makeResponse();

        $result = $controller->update($request, $response, ['id' => (string) $this->targetUserId]);

        $this->assertRedirect($result, '/users');
        $this->assertSame('Mitglied erfolgreich aktualisiert.', $_SESSION['success'] ?? null);
    }
}
