<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Controllers\ProjectController;
use App\Persistence\ProjectPersistence;
use App\Policies\ProjectMemberPolicy;
use App\Queries\ProjectQuery;
use App\Services\NameFormatterService;
use Illuminate\Database\Capsule\Manager as Capsule;
use Illuminate\Database\Schema\Blueprint;
use PHPUnit\Framework\TestCase;
use Psr\Http\Message\ResponseInterface;
use Slim\Views\Twig;

/**
 * Die Id-Listen der Query-Schicht müssen ein unbekanntes Mitglied verkraften.
 *
 * Zeigt die Session auf ein Konto, das es nicht mehr gibt - gelöschtes Mitglied,
 * Session aus einem anderen Datenbestand -, darf die Projektliste nicht mit
 * einem Fatal Error abbrechen, sondern muss ohne eigene Projekte rendern.
 */
class ProjectQueryMissingUserFeatureTest extends TestCase
{
    use TestHttpHelpers;

    private const MEMBER_ID = 1;
    private const MULTI_PROJECT_MEMBER_ID = 2;
    private const DELETED_USER_ID = 999;
    private const OWN_PROJECT = 1;
    private const FOREIGN_PROJECT = 2;
    private const ALTO = 3;
    private const TENOR = 7;

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
            $table->string('first_name')->default('');
            $table->string('last_name')->default('');
            $table->boolean('is_active')->default(true);
            $table->integer('last_project_id')->nullable();
        });
        $schema->create('projects', function (Blueprint $table): void {
            $table->increments('id');
            $table->string('name');
            $table->date('start_date')->nullable();
            $table->date('end_date')->nullable();
        });
        $schema->create('project_users', function (Blueprint $table): void {
            $table->integer('user_id');
            $table->integer('project_id');
        });
        $schema->create('voice_groups', function (Blueprint $table): void {
            $table->increments('id');
            $table->string('name');
        });
        $schema->create('user_voice_groups', function (Blueprint $table): void {
            $table->integer('user_id');
            $table->integer('voice_group_id');
            $table->integer('sub_voice_id')->nullable();
        });

        Capsule::table('users')->insert([
            [
                'id' => self::MEMBER_ID,
                'email' => 'saengerin@example.test',
                'first_name' => 'Marlene',
                'last_name' => 'Größing',
            ],
            [
                'id' => self::MULTI_PROJECT_MEMBER_ID,
                'email' => 'doppelt@example.test',
                'first_name' => 'Konrad',
                'last_name' => 'Vielsinger',
            ],
        ]);
        Capsule::table('projects')->insert([
            ['id' => self::OWN_PROJECT, 'name' => 'Frühjahrskonzert'],
            ['id' => self::FOREIGN_PROJECT, 'name' => 'Adventsingen'],
        ]);
        Capsule::table('project_users')->insert([
            [
                'user_id' => self::MEMBER_ID,
                'project_id' => self::OWN_PROJECT,
            ],
            [
                'user_id' => self::MULTI_PROJECT_MEMBER_ID,
                'project_id' => self::OWN_PROJECT,
            ],
            [
                'user_id' => self::MULTI_PROJECT_MEMBER_ID,
                'project_id' => self::FOREIGN_PROJECT,
            ],
        ]);
        Capsule::table('voice_groups')->insert([
            ['id' => self::ALTO, 'name' => 'Alt'],
            ['id' => self::TENOR, 'name' => 'Tenor'],
        ]);
        Capsule::table('user_voice_groups')->insert([
            [
                'user_id' => self::MEMBER_ID,
                'voice_group_id' => self::ALTO,
            ],
            [
                'user_id' => self::MULTI_PROJECT_MEMBER_ID,
                'voice_group_id' => self::ALTO,
            ],
            [
                'user_id' => self::MULTI_PROJECT_MEMBER_ID,
                'voice_group_id' => self::TENOR,
            ],
        ]);
    }

    protected function tearDown(): void
    {
        $_SESSION = [];
        Capsule::schema()->drop('user_voice_groups');
        Capsule::schema()->drop('voice_groups');
        Capsule::schema()->drop('project_users');
        Capsule::schema()->drop('projects');
        Capsule::schema()->drop('users');
        parent::tearDown();
    }

    public function testProjectIdsOfAMemberComeBackAsIntegers(): void
    {
        $query = new ProjectQuery(new NameFormatterService());

        $this->assertSame([self::OWN_PROJECT], $query->getUserProjectIds(self::MEMBER_ID));
    }

    public function testUnknownMemberHasNoProjectIds(): void
    {
        $query = new ProjectQuery(new NameFormatterService());

        $this->assertSame([], $query->getUserProjectIds(self::DELETED_USER_ID));
    }

    public function testVoiceGroupIdsOfAMemberComeBackAsIntegers(): void
    {
        $query = new ProjectQuery(new NameFormatterService());

        $this->assertSame([self::ALTO], $query->getUserVoiceGroupIds(self::MEMBER_ID));
    }

    /**
     * Ab der zweiten Id greift die Umwandlung auf einen anderen Schlüssel zu -
     * genau dort verrutschte eine Zahlenbasis-basierte Umwandlung.
     */
    public function testEveryProjectIdOfAMultiProjectMemberSurvivesTheConversion(): void
    {
        $query = new ProjectQuery(new NameFormatterService());

        $ids = $query->getUserProjectIds(self::MULTI_PROJECT_MEMBER_ID);
        sort($ids);

        $this->assertSame([self::OWN_PROJECT, self::FOREIGN_PROJECT], $ids);
    }

    public function testEveryVoiceGroupIdOfAMemberWithTwoVoiceGroupsSurvivesTheConversion(): void
    {
        $query = new ProjectQuery(new NameFormatterService());

        $ids = $query->getUserVoiceGroupIds(self::MULTI_PROJECT_MEMBER_ID);
        sort($ids);

        $this->assertSame([self::ALTO, self::TENOR], $ids);
    }

    public function testUnknownMemberHasNoVoiceGroupIds(): void
    {
        $query = new ProjectQuery(new NameFormatterService());

        $this->assertSame([], $query->getUserVoiceGroupIds(self::DELETED_USER_ID));
    }

    public function testIndexMarksTheOwnProjectsOfTheLoggedInMember(): void
    {
        $_SESSION['user_id'] = self::MEMBER_ID;

        $data = $this->renderIndex();

        $this->assertSame([self::OWN_PROJECT], $data['userProjectIds']);
    }

    public function testIndexRendersWhenTheSessionUserNoLongerExists(): void
    {
        $_SESSION['user_id'] = self::DELETED_USER_ID;

        $data = $this->renderIndex();

        $this->assertSame([], $data['userProjectIds']);
    }

    public function testIndexRendersWithoutAUserIdInTheSession(): void
    {
        $data = $this->renderIndex();

        $this->assertSame([], $data['userProjectIds']);
    }

    /**
     * @return array<string,mixed>
     */
    private function renderIndex(): array
    {
        $captured = [];

        $twig = $this->createMock(Twig::class);
        $twig->expects($this->once())
            ->method('render')
            ->willReturnCallback(
                function ($response, $template, $data) use (&$captured): ResponseInterface {
                    $captured = $data;
                    return $response;
                }
            );

        $controller = new ProjectController(
            $twig,
            new ProjectQuery(new NameFormatterService()),
            $this->createStub(ProjectPersistence::class),
            new ProjectMemberPolicy()
        );

        $controller->index($this->makeRequest('GET', '/projects'), $this->makeResponse());

        return $captured;
    }
}
