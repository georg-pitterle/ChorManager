<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\User;
use App\Queries\ProjectQuery;
use App\Queries\UserQuery;
use App\Services\NameFormatterService;
use Illuminate\Database\Capsule\Manager as Capsule;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Schema\Blueprint;
use PHPUnit\Framework\TestCase;

/**
 * Listenabfragen reichen ihre User-Modelle unverändert an die View-Schicht durch.
 * Sie dürfen deshalb nur die Spalten laden, die eine Liste wirklich braucht -
 * insbesondere nie den Passwort-Hash.
 */
class UserColumnExposureFeatureTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

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
            $table->string('password');
            $table->string('first_name')->nullable();
            $table->string('last_name')->nullable();
            $table->boolean('is_active')->default(true);
            $table->integer('last_project_id')->nullable();
        });
        $schema->create('roles', function (Blueprint $table): void {
            $table->increments('id');
            $table->string('name');
            $table->integer('hierarchy_level')->default(0);
        });
        $schema->create('user_roles', function (Blueprint $table): void {
            $table->integer('user_id');
            $table->integer('role_id');
        });
        $schema->create('voice_groups', function (Blueprint $table): void {
            $table->increments('id');
            $table->string('name');
        });
        $schema->create('sub_voices', function (Blueprint $table): void {
            $table->increments('id');
            $table->integer('voice_group_id');
            $table->string('name');
        });
        $schema->create('user_voice_groups', function (Blueprint $table): void {
            $table->integer('user_id');
            $table->integer('voice_group_id');
            $table->integer('sub_voice_id')->nullable();
        });
        $schema->create('projects', function (Blueprint $table): void {
            $table->increments('id');
            $table->string('name');
        });
        $schema->create('project_users', function (Blueprint $table): void {
            $table->integer('user_id');
            $table->integer('project_id');
        });
        // findById() lädt das Postfach eager mit.
        $schema->create('user_mail_accounts', function (Blueprint $table): void {
            $table->increments('id');
            $table->integer('user_id');
        });

        Capsule::table('users')->insert([
            [
                'id' => 1,
                'email' => 'member@example.test',
                'password' => '$2y$12$souldneverleavethequerylayer',
                'first_name' => 'Anna',
                'last_name' => 'Alt',
                'is_active' => 1,
                'last_project_id' => 10,
            ],
            [
                'id' => 2,
                'email' => 'free@example.test',
                'password' => '$2y$12$souldneverleavethequerylayer',
                'first_name' => 'Berta',
                'last_name' => 'Bass',
                'is_active' => 1,
                'last_project_id' => null,
            ],
            [
                'id' => 3,
                'email' => 'old@example.test',
                'password' => '$2y$12$souldneverleavethequerylayer',
                'first_name' => 'Clara',
                'last_name' => 'Chor',
                'is_active' => 0,
                'last_project_id' => null,
            ],
        ]);
        Capsule::table('voice_groups')->insert([['id' => 1, 'name' => 'Sopran']]);
        Capsule::table('user_voice_groups')->insert([
            ['user_id' => 1, 'voice_group_id' => 1, 'sub_voice_id' => null],
            ['user_id' => 2, 'voice_group_id' => 1, 'sub_voice_id' => null],
        ]);
        Capsule::table('projects')->insert([['id' => 10, 'name' => 'Adventkonzert']]);
        Capsule::table('project_users')->insert([['user_id' => 1, 'project_id' => 10]]);
    }

    /**
     * @return array<string, Collection>
     */
    private function listResults(): array
    {
        $userQuery = new UserQuery(new NameFormatterService());
        $projectQuery = new ProjectQuery(new NameFormatterService());

        return [
            'UserQuery::getAllUsers' => $userQuery->getAllUsers(),
            'UserQuery::getArchivedUsers' => $userQuery->getArchivedUsers(),
            'ProjectQuery::getProjectMembers' => $projectQuery->getProjectMembers(10),
            'ProjectQuery::getUsersNotInProject' => $projectQuery->getUsersNotInProject(10),
            'ProjectQuery::getUsersNotInProjectForVoiceGroups'
                => $projectQuery->getUsersNotInProjectForVoiceGroups(10, [1]),
        ];
    }

    public function testListQueriesNeverLoadThePasswordHash(): void
    {
        foreach ($this->listResults() as $label => $users) {
            $this->assertGreaterThan(0, $users->count(), $label . ' liefert keine Mitglieder zum Prüfen.');

            foreach ($users as $user) {
                $this->assertArrayNotHasKey(
                    'password',
                    $user->getAttributes(),
                    $label . ' lädt den Passwort-Hash in die View-Schicht.'
                );
            }
        }
    }

    public function testListQueriesStillProvideTheColumnsTheViewsNeed(): void
    {
        foreach ($this->listResults() as $label => $users) {
            foreach ($users as $user) {
                foreach (['id', 'email', 'first_name', 'last_name', 'is_active'] as $column) {
                    $this->assertArrayHasKey(
                        $column,
                        $user->getAttributes(),
                        $label . ' lädt die von den Listen benötigte Spalte ' . $column . ' nicht.'
                    );
                }
            }
        }
    }

    public function testListColumnsExcludeTheSensitiveOnes(): void
    {
        $this->assertNotContains('password', User::LIST_COLUMNS);
        $this->assertContains('id', User::LIST_COLUMNS, 'Ohne id lassen sich keine Relationen zuordnen.');
    }

    public function testAuthenticationLookupsStillLoadThePasswordHash(): void
    {
        $userQuery = new UserQuery(new NameFormatterService());

        $byEmail = $userQuery->findByEmail('member@example.test');
        $this->assertNotNull($byEmail);
        $this->assertArrayHasKey(
            'password',
            $byEmail->getAttributes(),
            'findByEmail() ist der Login-Pfad und braucht den Hash weiterhin.'
        );

        $byId = $userQuery->findById(1);
        $this->assertNotNull($byId);
        $this->assertArrayHasKey('password', $byId->getAttributes());
    }

    public function testRelationsStillResolveOnTheReducedSelection(): void
    {
        $members = (new ProjectQuery(new NameFormatterService()))->getProjectMembers(10);

        $this->assertCount(1, $members);
        $this->assertSame('Sopran', $members->first()->voiceGroups->first()->name);
    }
}
