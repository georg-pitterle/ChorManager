<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\User;
use App\Services\SessionAuthService;
use Illuminate\Support\Collection;
use PHPUnit\Framework\TestCase;

class AuthFeatureTest extends TestCase
{
    protected function setUp(): void
    {
        $_SESSION = [];
    }

    public function testAuthClassesAndMethodsExist(): void
    {
        $this->assertTrue(class_exists(\App\Controllers\AuthController::class));
        $this->assertTrue(class_exists(\App\Services\SessionAuthService::class));
        $this->assertTrue(class_exists(\App\Services\RememberLoginService::class));

        $this->assertTrue(method_exists(\App\Controllers\AuthController::class, 'showLogin'));
        $this->assertTrue(method_exists(\App\Controllers\AuthController::class, 'processLogin'));
        $this->assertTrue(method_exists(\App\Controllers\AuthController::class, 'showSetup'));
        $this->assertTrue(method_exists(\App\Controllers\AuthController::class, 'processSetup'));
        $this->assertTrue(method_exists(\App\Controllers\AuthController::class, 'logout'));
    }

    public function testAuthRoutesAndTemplatesExist(): void
    {
        $routesContent = file_get_contents(dirname(__DIR__) . '/../src/Routes.php');

        $this->assertIsString($routesContent);
        $this->assertStringContainsString("'/login'", $routesContent);
        $this->assertStringContainsString("'/logout'", $routesContent);
        $this->assertStringContainsString("'/setup'", $routesContent);
        $this->assertStringContainsString('/forgot-password', $routesContent);
        $this->assertStringContainsString('/reset-password', $routesContent);

        $this->assertTrue(file_exists(dirname(__DIR__) . '/../templates/auth/login.twig'));
        $this->assertTrue(file_exists(dirname(__DIR__) . '/../templates/auth/setup.twig'));
        $this->assertTrue(file_exists(dirname(__DIR__) . '/../templates/auth/forgot_password.twig'));
        $this->assertTrue(file_exists(dirname(__DIR__) . '/../templates/auth/reset_password.twig'));
    }

    public function testSessionAuthServiceSetsFlagsFromRolesAndVoiceGroups(): void
    {
        $service = new SessionAuthService();
        $user = new User();
        $user->id = 7;
        $user->first_name = 'Test';
        $user->last_name = 'User';

        $roles = new Collection([
            (object) [
                'hierarchy_level' => 60,
                'can_manage_users' => 0,
                'can_edit_users' => 1,
                'can_manage_attendance' => 1,
                'can_manage_project_members' => 0,
                'can_manage_finances' => 1,
                'can_manage_master_data' => 0,
                'can_manage_sponsoring' => 1,
                'can_manage_song_library' => 0,
                'can_manage_newsletters' => 1,
                'can_manage_tasks' => 0,
            ],
            (object) [
                'hierarchy_level' => 85,
                'can_manage_users' => 0,
                'can_edit_users' => 0,
                'can_manage_attendance' => 0,
                'can_manage_project_members' => 0,
                'can_manage_finances' => 0,
                'can_manage_master_data' => 1,
                'can_manage_sponsoring' => 0,
                'can_manage_song_library' => 1,
                'can_manage_newsletters' => 0,
                'can_manage_tasks' => 1,
            ],
        ]);

        $voiceGroups = new Collection([
            (object) ['id' => 2],
            (object) ['id' => 5],
        ]);

        $user->setRelation('roles', $roles);
        $user->setRelation('voiceGroups', $voiceGroups);

        $service->setAuthenticatedUser($user);

        $this->assertSame(7, $_SESSION['user_id']);
        $this->assertSame('Test User', $_SESSION['user_name']);
        $this->assertTrue($_SESSION['can_manage_users']);
        $this->assertTrue($_SESSION['can_edit_users']);
        $this->assertTrue($_SESSION['can_manage_attendance']);
        $this->assertTrue($_SESSION['can_manage_project_members']);
        $this->assertTrue($_SESSION['can_manage_finances']);
        $this->assertTrue($_SESSION['can_manage_master_data']);
        $this->assertTrue($_SESSION['can_manage_sponsoring']);
        $this->assertTrue($_SESSION['can_manage_song_library']);
        $this->assertTrue($_SESSION['can_manage_newsletters']);
        $this->assertTrue($_SESSION['can_manage_tasks']);
        $this->assertSame(85, $_SESSION['role_level']);
        $this->assertSame([2, 5], $_SESSION['voice_group_ids']);
    }
}
