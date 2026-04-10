<?php

declare(strict_types=1);

namespace Tests\Feature;

use PHPUnit\Framework\TestCase;

class UserManagementFeatureTest extends TestCase
{
    public function testUserManagementStructureExists(): void
    {
        $this->assertTrue(class_exists(\App\Controllers\UserController::class));
        $this->assertTrue(method_exists(\App\Controllers\UserController::class, 'index'));
        $this->assertTrue(method_exists(\App\Controllers\UserController::class, 'create'));
        $this->assertTrue(method_exists(\App\Controllers\UserController::class, 'update'));
        $this->assertTrue(method_exists(\App\Controllers\UserController::class, 'deactivate'));
        $this->assertTrue(method_exists(\App\Controllers\UserController::class, 'bulkDeactivate'));
        $this->assertTrue(method_exists(\App\Controllers\UserController::class, 'restore'));

        $routesContent = file_get_contents(dirname(__DIR__) . '/../src/Routes.php');
        $this->assertIsString($routesContent);
        $this->assertStringContainsString("'/users'", $routesContent);
        $this->assertStringContainsString("'/deactivate/{id:[0-9]+}'", $routesContent);
        $this->assertStringContainsString("'/bulk-deactivate'", $routesContent);
        $this->assertStringContainsString("'/restore/{id:[0-9]+}'", $routesContent);

        $this->assertTrue(file_exists(dirname(__DIR__) . '/../templates/users/manage.twig'));
    }

    public function testArchivedUsersQueryMethodExists(): void
    {
        $this->assertTrue(method_exists(\App\Queries\UserQuery::class, 'getArchivedUsers'));
    }

    public function testManageTemplateContainsArchivedToggle(): void
    {
        $twig = file_get_contents(dirname(__DIR__) . '/../templates/users/manage.twig');
        $this->assertIsString($twig);
        $this->assertStringContainsString('show_archived', $twig);
        $this->assertStringContainsString('/users?archived=1', $twig);
        $this->assertStringContainsString('/users/restore/', $twig);
    }

    public function testUserManagementBuildsProjectCountParticipationDataAndModalMarkup(): void
    {
        $controller = file_get_contents(dirname(__DIR__) . '/../src/Controllers/UserController.php');
        $query = file_get_contents(dirname(__DIR__) . '/../src/Queries/UserQuery.php');
        $twig = file_get_contents(dirname(__DIR__) . '/../templates/users/manage.twig');

        $this->assertIsString($controller);
        $this->assertIsString($query);
        $this->assertIsString($twig);

        $this->assertStringContainsString('$user->project_count = count($user->project_ids);', $controller);
        $this->assertStringContainsString('$user->project_participations = $this->buildProjectParticipations($user);', $controller);
        $this->assertStringNotContainsString("'status_label' => \$isArchived ? 'Archiviert' : 'Aktiv',", $controller);
        $this->assertStringNotContainsString("'is_archived' => \$isArchived,", $controller);
        $this->assertStringNotContainsString('private function isArchivedProject(Project $project): bool', $controller);
        $this->assertStringContainsString("User::with(['roles', 'voiceGroups.subVoices', 'subVoices.voiceGroup', 'projects'])", $query);
        $this->assertStringContainsString('user.project_participations', $twig);
        $this->assertStringContainsString('participation.name', $twig);
        $this->assertStringNotContainsString('participation.status_label', $twig);
        $this->assertStringNotContainsString('participation.is_archived', $twig);
    }
}
