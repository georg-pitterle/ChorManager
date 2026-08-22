<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Policies\ProjectMemberPolicy;
use App\Policies\TaskPolicy;
use Illuminate\Database\Capsule\Manager as Capsule;
use Illuminate\Database\Schema\Blueprint;
use PHPUnit\Framework\TestCase;

/**
 * Ein frisch angelegtes Projekt hat noch kein einziges Mitglied. Solange
 * Mitglieder- und Planungsseite eine eigene Projektmitgliedschaft verlangen,
 * kommt niemand hinein und niemand kann das erste Mitglied eintragen
 * (Henne-Ei-Problem).
 *
 * Deshalb gilt:
 *  - can_manage_project_members und can_manage_tasks wirken projektuebergreifend,
 *    ohne eigene Mitgliedschaft.
 *  - can_assign_own_voice_group_to_project bleibt auf die eigenen Projekte
 *    beschraenkt; dieses Recht ist bewusst eng gefasst.
 */
class ProjectAccessWithoutMembershipFeatureTest extends TestCase
{
    private const PROJECT_WITH_MEMBER = 1;
    private const EMPTY_PROJECT = 2;

    protected function setUp(): void
    {
        parent::setUp();
        $_SESSION = [];

        $capsule = new Capsule();
        $capsule->addConnection([
            'driver' => 'sqlite',
            'database' => ':memory:',
            'prefix' => '',
        ]);
        $capsule->setAsGlobal();
        $capsule->bootEloquent();

        $schema = $capsule->schema();
        $schema->create('users', function (Blueprint $table): void {
            $table->increments('id');
            $table->string('email');
            $table->string('first_name')->nullable();
            $table->string('last_name')->nullable();
            $table->boolean('is_active')->default(true);
        });
        $schema->create('projects', function (Blueprint $table): void {
            $table->increments('id');
            $table->string('name');
        });
        $schema->create('project_users', function (Blueprint $table): void {
            $table->integer('user_id');
            $table->integer('project_id');
        });

        Capsule::table('users')->insert([
            'id' => 1,
            'email' => 'admin@example.test',
            'first_name' => 'Alex',
            'last_name' => 'Admin',
            'is_active' => 1,
        ]);
        Capsule::table('projects')->insert([
            ['id' => self::PROJECT_WITH_MEMBER, 'name' => 'Laufendes Projekt'],
            ['id' => self::EMPTY_PROJECT, 'name' => 'Frisch angelegtes Projekt'],
        ]);
        Capsule::table('project_users')->insert([
            'user_id' => 1,
            'project_id' => self::PROJECT_WITH_MEMBER,
        ]);
    }

    protected function tearDown(): void
    {
        $_SESSION = [];
        Capsule::schema()->drop('project_users');
        Capsule::schema()->drop('projects');
        Capsule::schema()->drop('users');
        parent::tearDown();
    }

    public function testBroadManagerReachesProjectWithoutOwnMembership(): void
    {
        $_SESSION['user_id'] = 1;
        $_SESSION['can_manage_project_members'] = true;
        $_SESSION['can_assign_own_voice_group_to_project'] = false;

        $policy = new ProjectMemberPolicy();

        $this->assertTrue($policy->canViewMembers(self::EMPTY_PROJECT));
        $this->assertTrue($policy->canAddMember(self::EMPTY_PROJECT));
        $this->assertTrue($policy->canRemoveMember(self::EMPTY_PROJECT));
        $this->assertTrue($policy->canViewAllCandidates());
    }

    public function testBroadManagerListsEveryProjectAsAccessible(): void
    {
        $_SESSION['user_id'] = 1;
        $_SESSION['can_manage_project_members'] = true;

        $policy = new ProjectMemberPolicy();

        $this->assertSame(
            [self::PROJECT_WITH_MEMBER, self::EMPTY_PROJECT],
            $policy->getAccessibleProjectIds()
        );
    }

    public function testVoiceGroupScopedRightStaysLimitedToOwnProjects(): void
    {
        $_SESSION['user_id'] = 1;
        $_SESSION['can_manage_project_members'] = false;
        $_SESSION['can_assign_own_voice_group_to_project'] = true;
        $_SESSION['voice_group_ids'] = [7];

        $policy = new ProjectMemberPolicy();

        $this->assertSame([self::PROJECT_WITH_MEMBER], $policy->getAccessibleProjectIds());
        $this->assertTrue($policy->canViewMembers(self::PROJECT_WITH_MEMBER));
        $this->assertFalse($policy->canViewMembers(self::EMPTY_PROJECT));
    }

    public function testWithoutAnyProjectMemberRightNothingIsAccessible(): void
    {
        $_SESSION['user_id'] = 1;
        $_SESSION['can_manage_project_members'] = false;
        $_SESSION['can_assign_own_voice_group_to_project'] = false;

        $policy = new ProjectMemberPolicy();

        $this->assertSame([], $policy->getAccessibleProjectIds());
        $this->assertFalse($policy->canViewMembers(self::PROJECT_WITH_MEMBER));
    }

    public function testTaskManagerReachesPlanningOfProjectWithoutOwnMembership(): void
    {
        $_SESSION['user_id'] = 1;
        $_SESSION['can_manage_tasks'] = true;

        $this->assertTrue((new TaskPolicy())->canManageTasks());
    }

    public function testTaskPolicyStillRequiresTheTaskRight(): void
    {
        $_SESSION['user_id'] = 1;
        $_SESSION['can_manage_tasks'] = false;

        $this->assertFalse((new TaskPolicy())->canManageTasks());
    }
}
