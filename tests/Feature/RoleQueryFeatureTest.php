<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Queries\RoleQuery;
use App\Queries\UserQuery;
use Illuminate\Database\Capsule\Manager as Capsule;
use Illuminate\Database\Schema\Blueprint;
use PHPUnit\Framework\TestCase;

/**
 * Die Rollen-Abfrage lag als getRole() in der UserQuery und gehört zu den Rollen.
 */
class RoleQueryFeatureTest extends TestCase
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

        $capsule->schema()->create('roles', function (Blueprint $table): void {
            $table->increments('id');
            $table->string('name');
            $table->integer('hierarchy_level')->default(0);
        });

        Capsule::table('roles')->insert([
            ['id' => 1, 'name' => 'Chorleitung', 'hierarchy_level' => 80],
        ]);
    }

    public function testFindByIdReturnsTheRole(): void
    {
        $role = (new RoleQuery())->findById(1);

        $this->assertNotNull($role);
        $this->assertSame('Chorleitung', $role->name);
    }

    public function testFindByIdReturnsNullForAnUnknownRole(): void
    {
        $this->assertNull((new RoleQuery())->findById(999));
    }

    public function testUserQueryNoLongerCarriesTheRoleLookup(): void
    {
        $this->assertFalse(
            method_exists(UserQuery::class, 'getRole'),
            'getRole() gehört in die RoleQuery, nicht in die UserQuery.'
        );
    }
}
