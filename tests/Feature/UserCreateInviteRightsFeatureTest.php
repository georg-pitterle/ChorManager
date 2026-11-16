<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\MailQueue;
use App\Models\Role;
use App\Models\User;
use App\Models\VoiceGroup;
use App\Services\MailQueueService;
use Illuminate\Database\Capsule\Manager as Capsule;
use PHPUnit\Framework\TestCase;
use Slim\Views\Twig;
use Tests\Unit\Bootstrap;

/**
 * "Speichern und einladen" beim Anlegen folgt derselben Regel wie jede andere
 * Einladung: nur mit Mitgliederverwaltung.
 *
 * invite() und bulkInvite() verlangten das Recht schon, create() nicht - wer nur
 * die eigene Stimmgruppe verwaltet, konnte beim Anlegen trotzdem einladen.
 */
final class UserCreateInviteRightsFeatureTest extends TestCase
{
    use TestHttpHelpers;
    use BuildsControllers;

    private int $mailsQueued = 0;
    private Role $role;
    private VoiceGroup $voiceGroup;

    protected function setUp(): void
    {
        parent::setUp();
        Bootstrap::setupTestDatabase();
        Capsule::connection()->beginTransaction();

        $this->role = Role::create(['name' => 'Einladerecht ' . bin2hex(random_bytes(3)), 'hierarchy_level' => 1]);
        $this->voiceGroup = VoiceGroup::create(['name' => 'Einladerecht ' . bin2hex(random_bytes(3))]);
        $this->mailsQueued = 0;
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

    public function testMemberManagersInviteWhileCreating(): void
    {
        $_SESSION = $this->session(canManageUsers: true);

        $user = $this->create();

        $this->assertNotNull($user);
        $this->assertSame(1, $this->mailsQueued);
    }

    public function testVoiceGroupManagersCreateButDoNotInvite(): void
    {
        $_SESSION = $this->session(canManageUsers: false);

        $user = $this->create();

        $this->assertNotNull($user, 'Anlegen bleibt erlaubt: ' . ($_SESSION['error'] ?? ''));
        $this->assertSame(0, $this->mailsQueued, 'Ohne Mitgliederverwaltung darf keine Einladung hinausgehen.');
        $this->assertSame('Mitglied erfolgreich angelegt.', $_SESSION['success'] ?? null);
    }

    /**
     * @return array<string, mixed>
     */
    private function session(bool $canManageUsers): array
    {
        return [
            'user_id' => 1,
            'can_manage_users' => $canManageUsers,
            'can_edit_users' => false,
            'can_manage_own_voice_group' => !$canManageUsers,
            'can_manage_project_members' => false,
            'role_level' => 50,
            'voice_group_ids' => [(int) $this->voiceGroup->id],
        ];
    }

    private function create(): ?User
    {
        $email = 'einladerecht-' . bin2hex(random_bytes(5)) . '@example.test';
        $mailQueue = $this->createStub(MailQueueService::class);
        $mailQueue->method('enqueueInvitationMail')->willReturnCallback(function (): MailQueue {
            $this->mailsQueued++;

            return new MailQueue();
        });

        $controller = $this->userController([
            'view' => $this->createStub(Twig::class),
            'mailQueueService' => $mailQueue,
        ]);

        $controller->create(
            $this->makeRequest('POST', '/users', [
                'first_name' => 'Neue',
                'last_name' => 'Stimme',
                'email' => $email,
                'roles' => [(string) $this->role->id],
                'voice_groups' => [(string) $this->voiceGroup->id],
                'submit_action' => 'save_and_invite',
            ]),
            $this->makeResponse()
        );

        return User::where('email', $email)->first();
    }
}
