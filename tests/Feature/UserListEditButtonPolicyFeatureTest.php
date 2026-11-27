<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\Role;
use App\Models\User;
use App\Persistence\ProjectPersistence;
use App\Persistence\UserPersistence;
use App\Queries\UserQuery;
use Illuminate\Database\Eloquent\Collection as EloquentCollection;
use Illuminate\Support\Collection;
use PHPUnit\Framework\TestCase;
use Psr\Http\Message\ResponseInterface;
use Psr\Log\LoggerInterface;
use Slim\Views\Twig;
use Tests\Unit\Bootstrap;

/**
 * Der Bearbeiten-Knopf der Mitgliederliste folgt UserEditPolicy::canEdit() je
 * Zeile - nicht dem rohen Sitzungsflag can_edit_users.
 *
 * Zwei Richtungen liefen zuvor auseinander:
 *
 *  - Eine Rolle, deren Bearbeiten-Recht allein aus can_manage_project_members
 *    kommt (ausgeliefert: Chorleitung - can_manage_users ja, can_edit_users
 *    nein), bekam gar keinen Knopf, obwohl editForm() und update() den Zugriff
 *    annehmen. Die Projektzuordnung war über die Liste nicht erreichbar.
 *  - Umgekehrt zeigte die Liste den Knopf auch für ein ranghöheres Mitglied
 *    (ausgeliefert: Vorstand sieht Admin), wo editForm() zwangsläufig mit 403
 *    antwortet. Genau diese Sackgasse zu vermeiden ist der Grund, aus dem
 *    UserEditPolicy die Antwort je Zeile überhaupt berechnet.
 */
final class UserListEditButtonPolicyFeatureTest extends TestCase
{
    use TestHttpHelpers;
    use BuildsControllers;

    private string $suffix = '';

    /** @var array<int, int> hierarchy_level => Rollen-Id */
    private array $roleIdByLevel = [];

    protected function setUp(): void
    {
        parent::setUp();
        Bootstrap::setupTestDatabase();
        Bootstrap::getCapsule()?->connection()->beginTransaction();
        $_SESSION = [];
        $this->suffix = bin2hex(random_bytes(4));

        foreach ([0, 80, 100] as $level) {
            $this->roleIdByLevel[$level] = (int) Role::create([
                'name' => 'Rolle Level ' . $level . ' ' . $this->suffix,
                'hierarchy_level' => $level,
            ])->id;
        }
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
     * Rein synthetisches Mitglied: UserQuery ist gestubbt, die users-Tabelle
     * wird nicht angefasst.
     *
     * @param list<int> $roleLevels
     */
    private function makeMember(int $id, array $roleLevels): User
    {
        $member = new User();
        $member->id = $id;
        $member->first_name = 'Mit';
        $member->last_name = 'Glied' . $id;
        $member->email = 'member' . $id . $this->suffix . '@example.test';
        $member->is_active = 1;

        $roles = array_map(function (int $level): Role {
            $role = new Role();
            $role->id = $this->roleIdByLevel[$level];
            $role->hierarchy_level = $level;
            $role->name = 'Rolle Level ' . $level . ' ' . $this->suffix;
            return $role;
        }, $roleLevels);

        $member->setRelation('roles', new Collection($roles));
        $member->setRelation('voiceGroups', new Collection([]));
        $member->setRelation('projects', new Collection([]));

        return $member;
    }

    /**
     * Ruft index() auf und gibt die Daten zurück, die an die Vorlage gehen.
     *
     * @param list<User> $members
     * @return array<string, mixed>
     */
    private function renderData(array $members): array
    {
        $captured = [];

        $view = $this->createStub(Twig::class);
        $view->method('render')->willReturnCallback(
            function (ResponseInterface $response, string $template, array $data) use (&$captured): ResponseInterface {
                $captured = $data;
                return $response;
            }
        );

        $userQuery = $this->createStub(UserQuery::class);
        $userQuery->method('getActiveUsers')->willReturn(new EloquentCollection($members));
        $userQuery->method('getUsersForVoiceGroups')->willReturn(new EloquentCollection($members));

        $controller = $this->userController([
            'view' => $view,
            'userQuery' => $userQuery,
            'userPersistence' => $this->createStub(UserPersistence::class),
            'projectPersistence' => $this->createStub(ProjectPersistence::class),
            'logger' => $this->createStub(LoggerInterface::class),
        ]);

        $controller->index($this->makeRequest('GET', '/users'), $this->makeResponse());

        return $captured;
    }

    /**
     * Chorleitung-Fall: Das Bearbeiten-Recht kommt allein aus
     * can_manage_project_members. canEdit() sagt ja, also muss die Zeile als
     * bearbeitbar herauskommen.
     */
    public function testProjectMemberManagerWithoutEditUsersRightGetsAnEditableRow(): void
    {
        $_SESSION['user_id'] = 4711;
        $_SESSION['can_manage_users'] = true;
        $_SESSION['can_edit_users'] = false;
        $_SESSION['can_manage_project_members'] = true;
        $_SESSION['can_manage_own_voice_group'] = true;
        $_SESSION['role_level'] = 80;
        $_SESSION['voice_group_ids'] = [];

        $member = $this->makeMember(500001, [0]);

        $data = $this->renderData([$member]);

        $this->assertSame(
            [500001 => true],
            $data['can_edit_member'] ?? null,
            'canEditProjects() reicht für das Formular - die Zeile muss bearbeitbar sein.'
        );
    }

    /**
     * Vorstand-sieht-Admin-Fall: Mit dem Projektmitglieder-Recht ist die Zeile
     * bearbeitbar, denn die Projektzuordnung kennt die Hierarchie nicht mehr
     * (Antwort A aus Review-Lauf 87). Das Formular zeigt dort dann nur die
     * Zuordnung - canEditProfile() bleibt für dieses Ziel nein.
     */
    public function testRowOfAnOutrankingMemberIsEditableForItsProjectAssignment(): void
    {
        $_SESSION['user_id'] = 4711;
        $_SESSION['can_manage_users'] = true;
        $_SESSION['can_edit_users'] = true;
        $_SESSION['can_manage_project_members'] = true;
        $_SESSION['can_manage_own_voice_group'] = true;
        $_SESSION['role_level'] = 80;
        $_SESSION['voice_group_ids'] = [];

        $data = $this->renderData([
            $this->makeMember(500002, [100]),
            $this->makeMember(500003, [0]),
        ]);

        $this->assertSame(
            [500002 => true, 500003 => true],
            $data['can_edit_member'] ?? null,
            'Die Projektzuordnung öffnet das Formular auch beim ranghöheren Mitglied.'
        );
    }

    /**
     * Ohne das Projektmitglieder-Recht bleibt die Zeile des ranghöheren
     * Mitglieds zu: Dann gibt es darin nichts, was diese Sitzung schreiben
     * dürfte, und der Knopf wäre wieder die 403-Sackgasse von oben.
     */
    public function testRowOfAnOutrankingMemberStaysClosedWithoutTheProjectRight(): void
    {
        $_SESSION['user_id'] = 4711;
        $_SESSION['can_manage_users'] = true;
        $_SESSION['can_edit_users'] = true;
        $_SESSION['can_manage_project_members'] = false;
        $_SESSION['can_manage_own_voice_group'] = true;
        $_SESSION['role_level'] = 80;
        $_SESSION['voice_group_ids'] = [];

        $data = $this->renderData([
            $this->makeMember(500002, [100]),
            $this->makeMember(500003, [0]),
        ]);

        $this->assertSame(
            [500003 => true],
            $data['can_edit_member'] ?? null,
            'Ein ranghöheres Mitglied bleibt ohne das Recht unantastbar, das gleichrangige nicht.'
        );
    }

    /**
     * Die Vorlage selbst: Die Knöpfe je Zeile hängen an der Antwort der Policy,
     * nicht am rohen Sitzungsflag. Ohne diesen Wächter wandert der Knopf beim
     * nächsten Eingriff zurück auf `can_edit_users`, und beide Sackgassen oben
     * sind wieder da - die Policy berechnet die Antwort dann weiterhin, nur
     * sieht sie niemand.
     */
    public function testTemplateGatesRowActionsOnThePolicyAnswer(): void
    {
        $template = (string) file_get_contents(
            dirname(__DIR__, 2) . '/templates/users/manage.twig'
        );

        $this->assertStringContainsString(
            '{% set _may_edit = can_edit_member[user.id]|default(false) %}',
            $template
        );
        $this->assertStringContainsString('{% elseif not _may_edit %}', $template);
        $this->assertStringNotContainsString(
            'can_edit_users',
            $template,
            'Das rohe Sitzungsflag entscheidet nicht mehr über die Knöpfe einer Zeile.'
        );
    }

    /**
     * Eine nicht bearbeitbare Zeile zeigt nur den Grund, keinen Knopf.
     *
     * In der aktiven Liste folgt das Archivieren dem Bearbeiten: canDeactivate()
     * verlangt canArchive(), das verlangt holdsProfileRight() und eine nicht
     * ranghöhere Zielperson - also genau canEditProfile(), weil jede Zeile der
     * Liste aktiv ist. Wer eine Zeile nicht bearbeiten darf, darf sie damit auch
     * nicht archivieren. Der zweite Zweig trug dort trotzdem einen
     * Archivieren-Knopf, der nur noch `disabled` sein konnte.
     */
    public function testANonEditableRowShowsOnlyTheReason(): void
    {
        $template = (string) file_get_contents(
            dirname(__DIR__, 2) . '/templates/users/manage.twig'
        );

        $this->assertStringContainsString(
            '<span class="d-block small text-muted user-archive-hint">{{ _archive_block_reason }}</span>',
            $template,
            'Statt des stets gesperrten Knopfes steht dort der Grund.'
        );
        $this->assertStringNotContainsString(
            'class="btn btn-sm btn-outline-danger"',
            $template,
            'Kein Archivieren-Knopf je Zeile, der nur noch gesperrt sein kann.'
        );
    }

    /**
     * Und der Controller schickt es nicht mehr mit. Zuvor stand `can_edit_users`
     * für jede Sitzung ohne can_manage_users auf hart `true` - ein Flag, das
     * genau dort log, wo die Policy längst zeilenweise antwortet.
     */
    public function testControllerNoLongerShipsTheRawEditFlag(): void
    {
        $_SESSION['user_id'] = 4711;
        $_SESSION['can_manage_users'] = false;
        $_SESSION['can_edit_users'] = false;
        $_SESSION['can_manage_project_members'] = false;
        $_SESSION['can_manage_own_voice_group'] = true;
        $_SESSION['role_level'] = 50;
        $_SESSION['voice_group_ids'] = [];

        $data = $this->renderData([$this->makeMember(500004, [0])]);

        $this->assertArrayNotHasKey('can_edit_users', $data);
    }
}
