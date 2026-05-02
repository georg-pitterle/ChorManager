<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Controllers\ProjectController;
use App\Policies\ProjectMemberPolicy;
use PHPUnit\Framework\TestCase;
use Slim\Views\Twig;

class ProjectMemberAccessFeatureTest extends TestCase
{
    use TestHttpHelpers;

    protected function setUp(): void
    {
        parent::setUp();
        $_SESSION = [];
    }

    public function testShowMembersReturns403WhenPolicyDeniesAccess(): void
    {
        $twig = $this->createMock(Twig::class);
        $twig->expects($this->never())->method('render');

        $projectQuery = $this->createMock(\App\Queries\ProjectQuery::class);
        $projectQuery->expects($this->never())
            ->method('findById')
            ->with(5);

        $projectPersistence = $this->createMock(\App\Persistence\ProjectPersistence::class);
        $policy = $this->createMock(ProjectMemberPolicy::class);
        $policy->expects($this->once())
            ->method('canViewMembers')
            ->with(5)
            ->willReturn(false);

        $controller = new ProjectController($twig, $projectQuery, $projectPersistence, $policy);
        $request = $this->makeRequest('GET', '/projects/5/members');
        $response = $this->makeResponse();

        $result = $controller->showMembers($request, $response, ['id' => '5']);

        $this->assertSame(403, $result->getStatusCode());
    }

    public function testAddMemberReturns403WhenPolicyDeniesAccess(): void
    {
        $twig = $this->createMock(Twig::class);
        $projectQuery = $this->createMock(\App\Queries\ProjectQuery::class);

        $projectPersistence = $this->createMock(\App\Persistence\ProjectPersistence::class);
        $projectPersistence->expects($this->never())->method('addProjectMember');

        $policy = $this->createMock(ProjectMemberPolicy::class);
        $policy->expects($this->once())
            ->method('canAddMember')
            ->with(12)
            ->willReturn(false);

        $controller = new ProjectController($twig, $projectQuery, $projectPersistence, $policy);
        $request = $this->makeRequest('POST', '/projects/12/members/add', ['user_id' => 99]);
        $response = $this->makeResponse();

        $result = $controller->addMember($request, $response, ['id' => '12']);

        $this->assertSame(403, $result->getStatusCode());
    }

    public function testRemoveMemberReturns403WhenPolicyDeniesAccess(): void
    {
        $twig = $this->createMock(Twig::class);
        $projectQuery = $this->createMock(\App\Queries\ProjectQuery::class);

        $projectPersistence = $this->createMock(\App\Persistence\ProjectPersistence::class);
        $projectPersistence->expects($this->never())->method('removeProjectMember');

        $policy = $this->createMock(ProjectMemberPolicy::class);
        $policy->expects($this->once())
            ->method('canRemoveMember')
            ->with(9)
            ->willReturn(false);

        $controller = new ProjectController($twig, $projectQuery, $projectPersistence, $policy);
        $request = $this->makeRequest('POST', '/projects/9/members/7/remove');
        $response = $this->makeResponse();

        $result = $controller->removeMember($request, $response, ['id' => '9', 'user_id' => '7']);

        $this->assertSame(403, $result->getStatusCode());
    }
}
