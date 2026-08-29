<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\Project;
use App\Policies\ProjectMemberPolicy;
use App\Policies\TaskPolicy;
use Illuminate\Database\Capsule\Manager as Capsule;
use PHPUnit\Framework\TestCase;
use Tests\Unit\Bootstrap;

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
    private int $adminUserId = 0;
    private int $projectWithMemberId = 0;
    private int $emptyProjectId = 0;

    protected function setUp(): void
    {
        parent::setUp();
        Bootstrap::setupTestDatabase();
        Bootstrap::getCapsule()?->connection()->beginTransaction();
        $_SESSION = [];

        $suffix = bin2hex(random_bytes(4));

        $this->adminUserId = (int) Capsule::table('users')->insertGetId([
            'email' => 'admin' . $suffix . '@example.test',
            'password' => password_hash('irrelevant', PASSWORD_DEFAULT),
            'first_name' => 'Alex',
            'last_name' => 'Admin',
            'is_active' => 1,
        ]);

        $this->projectWithMemberId = (int) Capsule::table('projects')->insertGetId([
            'name' => 'Laufendes Projekt ' . $suffix,
        ]);
        $this->emptyProjectId = (int) Capsule::table('projects')->insertGetId([
            'name' => 'Frisch angelegtes Projekt ' . $suffix,
        ]);

        Capsule::table('project_users')->insert([
            'user_id' => $this->adminUserId,
            'project_id' => $this->projectWithMemberId,
        ]);
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

    public function testBroadManagerReachesProjectWithoutOwnMembership(): void
    {
        $_SESSION['user_id'] = $this->adminUserId;
        $_SESSION['can_manage_project_members'] = true;
        $_SESSION['can_assign_own_voice_group_to_project'] = false;

        $policy = new ProjectMemberPolicy();

        $this->assertTrue($policy->canViewMembers($this->emptyProjectId));
        $this->assertTrue($policy->canAddMember($this->emptyProjectId));
        $this->assertTrue($policy->canRemoveMember($this->emptyProjectId));
        $this->assertTrue($policy->canViewAllCandidates());
    }

    public function testBroadManagerListsEveryProjectAsAccessible(): void
    {
        $_SESSION['user_id'] = $this->adminUserId;
        $_SESSION['can_manage_project_members'] = true;

        $policy = new ProjectMemberPolicy();

        // Gegen die echte Datenbank gibt es neben den beiden selbst angelegten
        // Projekten auch Bestandsprojekte. Das breite Recht muss trotzdem
        // WIRKLICH jedes Projekt liefern - geprueft ueber die beiden eigenen
        // Ids und ueber die Gesamtzahl, die exakt der Projekttabelle entspricht.
        $accessibleProjectIds = $policy->getAccessibleProjectIds();

        $this->assertContains($this->projectWithMemberId, $accessibleProjectIds);
        $this->assertContains($this->emptyProjectId, $accessibleProjectIds);
        $this->assertCount((int) Project::query()->count(), $accessibleProjectIds);
    }

    public function testVoiceGroupScopedRightStaysLimitedToOwnProjects(): void
    {
        $_SESSION['user_id'] = $this->adminUserId;
        $_SESSION['can_manage_project_members'] = false;
        $_SESSION['can_assign_own_voice_group_to_project'] = true;
        $_SESSION['voice_group_ids'] = [7];

        $policy = new ProjectMemberPolicy();

        $this->assertSame([$this->projectWithMemberId], $policy->getAccessibleProjectIds());
        $this->assertTrue($policy->canViewMembers($this->projectWithMemberId));
        $this->assertFalse($policy->canViewMembers($this->emptyProjectId));
    }

    public function testWithoutAnyProjectMemberRightNothingIsAccessible(): void
    {
        $_SESSION['user_id'] = $this->adminUserId;
        $_SESSION['can_manage_project_members'] = false;
        $_SESSION['can_assign_own_voice_group_to_project'] = false;

        $policy = new ProjectMemberPolicy();

        $this->assertSame([], $policy->getAccessibleProjectIds());
        $this->assertFalse($policy->canViewMembers($this->projectWithMemberId));
    }

    /**
     * Auch das breite Recht endet an einer Projekt-Id, die es gar nicht gibt.
     * Sonst nimmt ProjectController::addMember() die Zuordnung an und der
     * Fremdschlüssel project_users_ibfk_1 quittiert sie mit einem HTTP 500 -
     * genau der Fall, den die Existenzprüfung des Mitglieds dort schon abfängt.
     */
    public function testBroadManagerIsDeniedForAProjectThatDoesNotExist(): void
    {
        $_SESSION['user_id'] = $this->adminUserId;
        $_SESSION['can_manage_project_members'] = true;

        $policy = new ProjectMemberPolicy();
        $unknownProjectId = ((int) Project::query()->max('id')) + 1000;

        $this->assertFalse($policy->canViewMembers($unknownProjectId));
        $this->assertFalse($policy->canAddMember($unknownProjectId));
        $this->assertFalse($policy->canRemoveMember($unknownProjectId));
        $this->assertFalse($policy->canManageMember($unknownProjectId, [7]));
    }

    /**
     * Die Session trägt heute echte Booleans (SessionAuthService), Middleware und
     * Controller lesen sie aber überall nur auf Wahrheitswert. Eine Policy, die
     * strikt auf === true prüft, würde bei einer 1 aus einer anderen Quelle still
     * verweigern, obwohl die Middleware den Request längst durchgelassen hat.
     */
    public function testTruthyPermissionValuesCountAsGranted(): void
    {
        $_SESSION['user_id'] = $this->adminUserId;
        $_SESSION['can_manage_project_members'] = 1;

        $policy = new ProjectMemberPolicy();

        $this->assertTrue($policy->canViewMembers($this->emptyProjectId));
        $this->assertTrue($policy->canViewAllCandidates());
    }

    public function testTruthyTaskPermissionValueCountsAsGranted(): void
    {
        $_SESSION['user_id'] = $this->adminUserId;
        $_SESSION['can_manage_tasks'] = 1;

        $this->assertTrue((new TaskPolicy())->canManageTasks());
    }

    public function testTaskManagerReachesPlanningOfProjectWithoutOwnMembership(): void
    {
        $_SESSION['user_id'] = $this->adminUserId;
        $_SESSION['can_manage_tasks'] = true;

        $this->assertTrue((new TaskPolicy())->canManageTasks());
    }

    public function testTaskPolicyStillRequiresTheTaskRight(): void
    {
        $_SESSION['user_id'] = $this->adminUserId;
        $_SESSION['can_manage_tasks'] = false;

        $this->assertFalse((new TaskPolicy())->canManageTasks());
    }
}
