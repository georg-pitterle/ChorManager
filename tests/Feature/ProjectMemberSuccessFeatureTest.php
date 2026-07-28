<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Controllers\ProjectController;
use App\Policies\ProjectMemberPolicy;
use Illuminate\Database\Eloquent\Collection;
use PHPUnit\Framework\TestCase;
use Slim\Views\Twig;

class ProjectMemberSuccessFeatureTest extends TestCase
{
    use TestHttpHelpers;

    protected function setUp(): void
    {
        parent::setUp();
        $_SESSION = [];
    }

    public function testShowMembersReturns200WhenPolicyAllowsAccess(): void
    {
        $twig = $this->createMock(Twig::class);
        $twig->expects($this->once())
            ->method('render')
            ->willReturnCallback(function ($response, $template, $data) {
                return $response->withStatus(200);
            });

        $project = $this->createStub(\App\Models\Project::class);
        $members = new Collection([]);
        $available = new Collection([]);

        $projectQuery = $this->createMock(\App\Queries\ProjectQuery::class);
        $projectQuery->expects($this->once())
            ->method('findById')
            ->with(42)
            ->willReturn($project);
        $projectQuery->expects($this->once())
            ->method('getProjectMembers')
            ->with(42)
            ->willReturn($members);
        $projectQuery->expects($this->once())
            ->method('getUsersNotInProject')
            ->with(42)
            ->willReturn($available);

        $projectPersistence = $this->createStub(\App\Persistence\ProjectPersistence::class);

        $policy = $this->createMock(ProjectMemberPolicy::class);
        $policy->expects($this->once())
            ->method('canViewMembers')
            ->with(42)
            ->willReturn(true);

        $controller = new ProjectController($twig, $projectQuery, $projectPersistence, $policy);
        $request = $this->makeRequest('GET', '/projects/42/members');
        $response = $this->makeResponse();

        $result = $controller->showMembers($request, $response, ['id' => '42']);

        $this->assertSame(200, $result->getStatusCode());
    }

    public function testAddMemberSucceedsWhenPolicyAllowsAccess(): void
    {
        $twig = $this->createStub(Twig::class);
        $projectQuery = $this->createStub(\App\Queries\ProjectQuery::class);

        $projectPersistence = $this->createMock(\App\Persistence\ProjectPersistence::class);
        $projectPersistence->expects($this->once())
            ->method('addProjectMember')
            ->with(5, 3);

        $policy = $this->createMock(ProjectMemberPolicy::class);
        $policy->expects($this->once())
            ->method('canAddMember')
            ->with(5)
            ->willReturn(true);

        $controller = new ProjectController($twig, $projectQuery, $projectPersistence, $policy);
        $request = $this->makeRequest('POST', '/projects/5/members/add', ['user_id' => 3]);
        $response = $this->makeResponse();

        $result = $controller->addMember($request, $response, ['id' => '5']);

        $this->assertRedirect($result, '/projects/5/members');
    }

    public function testRemoveMemberSucceedsWhenPolicyAllowsAccess(): void
    {
        $twig = $this->createStub(Twig::class);
        $projectQuery = $this->createStub(\App\Queries\ProjectQuery::class);

        $projectPersistence = $this->createMock(\App\Persistence\ProjectPersistence::class);
        $projectPersistence->expects($this->once())
            ->method('removeProjectMember')
            ->with(7, 11);

        $policy = $this->createMock(ProjectMemberPolicy::class);
        $policy->expects($this->once())
            ->method('canRemoveMember')
            ->with(7)
            ->willReturn(true);

        $controller = new ProjectController($twig, $projectQuery, $projectPersistence, $policy);
        $request = $this->makeRequest('POST', '/projects/7/members/11/remove');
        $response = $this->makeResponse();

        $result = $controller->removeMember($request, $response, ['id' => '7', 'user_id' => '11']);

        $this->assertRedirect($result, '/projects/7/members');
    }
}
