<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Queries\UserQuery;
use App\Services\NameFormatterService;
use Illuminate\Database\Capsule\Manager as Capsule;
use Illuminate\Database\Schema\Blueprint;
use PHPUnit\Framework\TestCase;

/**
 * Der Sitzungs-Lookup läuft bei jedem geschützten Request. Er darf deshalb nur die
 * Relationen laden, die der Rechte-Schnappschuss auswertet, und keinesfalls den
 * Passwort-Hash in eine Abfrage ziehen, die auf jedem Seitenaufruf stattfindet.
 * Die Detailmasken brauchen weiterhin den vollständigen Lookup.
 */
class SessionUserLookupFeatureTest extends TestCase
{
    private const SESSION_RELATIONS = ['roles', 'voiceGroups'];
    private const DETAIL_RELATIONS = ['subVoices', 'mailAccount'];

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
        $schema->create('user_mail_accounts', function (Blueprint $table): void {
            $table->increments('id');
            $table->integer('user_id');
        });

        Capsule::table('users')->insert([
            'id' => 1,
            'email' => 'member@example.test',
            'password' => '$2y$12$souldneverleavethequerylayer',
            'first_name' => 'Anna',
            'last_name' => 'Alt',
            'is_active' => 1,
            'last_project_id' => null,
        ]);
        Capsule::table('roles')->insert(['id' => 1, 'name' => 'Mitglied', 'hierarchy_level' => 0]);
        Capsule::table('user_roles')->insert(['user_id' => 1, 'role_id' => 1]);
        Capsule::table('voice_groups')->insert(['id' => 1, 'name' => 'Sopran']);
        Capsule::table('sub_voices')->insert(['id' => 1, 'voice_group_id' => 1, 'name' => 'Sopran 1']);
        Capsule::table('user_voice_groups')->insert([
            'user_id' => 1,
            'voice_group_id' => 1,
            'sub_voice_id' => 1,
        ]);
        Capsule::table('user_mail_accounts')->insert(['id' => 1, 'user_id' => 1]);
    }

    private function query(): UserQuery
    {
        return new UserQuery(new NameFormatterService());
    }

    public function testSitzungsLookupLaedtDenPasswortHashNicht(): void
    {
        $user = $this->query()->findForSession(1);

        $this->assertNotNull($user);
        $this->assertArrayNotHasKey(
            'password',
            $user->getAttributes(),
            'Der Sitzungs-Lookup läuft bei jedem Request und darf den Hash nicht mitladen.'
        );
    }

    public function testSitzungsLookupLiefertAlleFuerDenRechteSchnappschussNoetigenDaten(): void
    {
        $user = $this->query()->findForSession(1);

        $this->assertNotNull($user);

        foreach (['id', 'first_name', 'last_name', 'is_active'] as $column) {
            $this->assertArrayHasKey($column, $user->getAttributes(), 'Spalte ' . $column . ' fehlt.');
        }

        foreach (self::SESSION_RELATIONS as $relation) {
            $this->assertTrue(
                $user->relationLoaded($relation),
                'Der Sitzungsaufbau liest ' . $relation . ' und braucht die Relation eager geladen.'
            );
        }

        $this->assertSame([1], $user->voiceGroups->pluck('id')->all());
        $this->assertSame('Mitglied', $user->roles->first()->name);
    }

    public function testSitzungsLookupLaedtKeineDetailRelationen(): void
    {
        $user = $this->query()->findForSession(1);

        $this->assertNotNull($user);

        foreach (self::DETAIL_RELATIONS as $relation) {
            $this->assertFalse(
                $user->relationLoaded($relation),
                'Relation ' . $relation . ' kostet eine Abfrage pro Request und wird dort nicht gelesen.'
            );
        }

        $this->assertFalse(
            $user->voiceGroups->first()->relationLoaded('subVoices'),
            'Die Teilstimmen der Stimmgruppe werden im Sitzungsaufbau nicht gelesen.'
        );
    }

    public function testLoginLookupBehaeltDenHashUndLaedtNurDieSitzungsRelationen(): void
    {
        $user = $this->query()->findByEmail('member@example.test');

        $this->assertNotNull($user);
        $this->assertArrayHasKey(
            'password',
            $user->getAttributes(),
            'password_verify() braucht den Hash aus dem Login-Lookup.'
        );

        foreach (self::SESSION_RELATIONS as $relation) {
            $this->assertTrue($user->relationLoaded($relation));
        }

        foreach (self::DETAIL_RELATIONS as $relation) {
            $this->assertFalse(
                $user->relationLoaded($relation),
                'Der Login wertet ' . $relation . ' nicht aus.'
            );
        }
    }

    public function testDetailLookupLaedtWeiterhinTeilstimmenUndPostfach(): void
    {
        $user = $this->query()->findById(1);

        $this->assertNotNull($user);

        foreach (self::DETAIL_RELATIONS as $relation) {
            $this->assertTrue(
                $user->relationLoaded($relation),
                'Die Detailmasken zeigen ' . $relation . ' und brauchen die Relation weiterhin.'
            );
        }

        $this->assertSame('Sopran 1', $user->subVoices->first()->name);
    }
}
