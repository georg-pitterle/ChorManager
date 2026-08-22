<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Controllers\ProjectController;
use App\Persistence\ProjectPersistence;
use App\Policies\ProjectMemberPolicy;
use App\Queries\ProjectQuery;
use PHPUnit\Framework\TestCase;
use Slim\Views\Twig;

/**
 * Ein unbekanntes Mitglied darf nie bis zur Persistenz durchrutschen: der
 * Fremdschlüssel auf `users` wirft dort eine Datenbankausnahme (HTTP 500),
 * statt dem Benutzer eine verständliche Meldung zu zeigen.
 */
class ProjectMemberUnknownUserFeatureTest extends TestCase
{
    use TestHttpHelpers;

    protected function setUp(): void
    {
        parent::setUp();
        $_SESSION = [];
    }

    public function testAddMemberRejectsUnknownUserId(): void
    {
        $projectQuery = $this->createMock(ProjectQuery::class);
        $projectQuery->expects($this->once())
            ->method('userExists')
            ->with(999)
            ->willReturn(false);

        $projectPersistence = $this->createMock(ProjectPersistence::class);
        $projectPersistence->expects($this->never())->method('addProjectMember');

        $policy = $this->createMock(ProjectMemberPolicy::class);
        $policy->expects($this->once())
            ->method('canAddMember')
            ->with(5)
            ->willReturn(true);
        $policy->expects($this->never())->method('canManageMember');

        $controller = new ProjectController(
            $this->createStub(Twig::class),
            $projectQuery,
            $projectPersistence,
            $policy
        );

        $result = $controller->addMember(
            $this->makeRequest('POST', '/projects/5/members/add', ['user_id' => 999]),
            $this->makeResponse(),
            ['id' => '5']
        );

        $this->assertRedirect($result, '/projects/5/members');
        $this->assertSame('Das ausgewählte Mitglied existiert nicht.', $_SESSION['error'] ?? null);
        $this->assertArrayNotHasKey('success', $_SESSION);
    }

    public function testAddMemberStillChecksVoiceGroupScopeForKnownUser(): void
    {
        $projectQuery = $this->createMock(ProjectQuery::class);
        $projectQuery->expects($this->once())
            ->method('userExists')
            ->with(3)
            ->willReturn(true);
        $projectQuery->expects($this->once())
            ->method('getUserVoiceGroupIds')
            ->with(3)
            ->willReturn([4]);

        $projectPersistence = $this->createMock(ProjectPersistence::class);
        $projectPersistence->expects($this->never())->method('addProjectMember');

        $policy = $this->createMock(ProjectMemberPolicy::class);
        $policy->expects($this->once())
            ->method('canAddMember')
            ->with(5)
            ->willReturn(true);
        $policy->expects($this->once())
            ->method('canManageMember')
            ->with(5, [4])
            ->willReturn(false);

        $controller = new ProjectController(
            $this->createStub(Twig::class),
            $projectQuery,
            $projectPersistence,
            $policy
        );

        $result = $controller->addMember(
            $this->makeRequest('POST', '/projects/5/members/add', ['user_id' => 3]),
            $this->makeResponse(),
            ['id' => '5']
        );

        $this->assertRedirect($result, '/projects/5/members');
        $this->assertSame(
            'Sie dürfen nur Mitglieder Ihrer eigenen Stimmgruppe zuweisen.',
            $_SESSION['error'] ?? null
        );
    }
}
