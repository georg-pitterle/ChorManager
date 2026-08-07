<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\SubVoice;
use App\Models\User;
use App\Models\VoiceGroup;
use App\Util\VoiceGroupOrder;
use Illuminate\Database\Capsule\Manager as Capsule;
use Illuminate\Database\Schema\Blueprint;
use PHPUnit\Framework\TestCase;

/**
 * Locks in the project convention: every listing/iteration over voice groups
 * must be ordered Sopran, Alt, Tenor, Bass (canonical seed id order); sub
 * voices within a group are ordered alphabetically.
 */
class VoiceGroupOrderingFeatureTest extends TestCase
{
    protected function setUp(): void
    {
        $capsule = new Capsule();
        $capsule->addConnection([
            'driver' => 'sqlite',
            'database' => ':memory:',
            'prefix' => '',
        ]);
        $capsule->setAsGlobal();
        $capsule->bootEloquent();

        $schema = $capsule->schema();
        $schema->create('voice_groups', function (Blueprint $table): void {
            $table->increments('id');
            $table->string('name');
        });
        $schema->create('sub_voices', function (Blueprint $table): void {
            $table->increments('id');
            $table->string('name');
            $table->integer('voice_group_id');
        });
        $schema->create('users', function (Blueprint $table): void {
            $table->increments('id');
            $table->string('first_name')->nullable();
            $table->string('last_name')->nullable();
        });
        $schema->create('user_voice_groups', function (Blueprint $table): void {
            $table->integer('user_id');
            $table->integer('voice_group_id');
            $table->integer('sub_voice_id')->nullable();
        });

        // Canonical SATB seed ids, plus a custom group appended afterwards.
        Capsule::table('voice_groups')->insert([
            ['id' => 1, 'name' => 'Sopran'],
            ['id' => 2, 'name' => 'Alt'],
            ['id' => 3, 'name' => 'Tenor'],
            ['id' => 4, 'name' => 'Bass'],
            ['id' => 5, 'name' => 'Solisten'],
        ]);

        // Insert sub voices in deliberately non-alphabetical order.
        Capsule::table('sub_voices')->insert([
            ['id' => 1, 'name' => 'Sopran 2', 'voice_group_id' => 1],
            ['id' => 2, 'name' => 'Sopran 1', 'voice_group_id' => 1],
        ]);
    }

    public function testSortNameKeyedMapReturnsSatbOrder(): void
    {
        $map = [
            'Bass' => ['x'],
            'Tenor' => ['x'],
            'Ohne Stimmgruppe' => ['x'],
            'Sopran' => ['x'],
            'Solisten' => ['x'],
            'Alt' => ['x'],
        ];

        $sorted = VoiceGroupOrder::sortNameKeyedMap($map, ['Ohne Stimmgruppe']);

        $this->assertSame(
            ['Sopran', 'Alt', 'Tenor', 'Bass', 'Solisten', 'Ohne Stimmgruppe'],
            array_keys($sorted)
        );
    }

    public function testSortNameKeyedMapPreservesValues(): void
    {
        $map = [
            'Alt' => ['a1', 'a2'],
            'Sopran' => ['s1'],
        ];

        $sorted = VoiceGroupOrder::sortNameKeyedMap($map, []);

        $this->assertSame(['s1'], $sorted['Sopran']);
        $this->assertSame(['a1', 'a2'], $sorted['Alt']);
    }

    public function testVoiceGroupSubVoicesRelationIsAlphabetical(): void
    {
        $group = VoiceGroup::with('subVoices')->find(1);

        $this->assertSame(
            ['Sopran 1', 'Sopran 2'],
            $group->subVoices->pluck('name')->all()
        );
    }

    public function testUserVoiceGroupsRelationIsSatbOrder(): void
    {
        Capsule::table('users')->insert(['id' => 1, 'first_name' => 'A', 'last_name' => 'B']);
        // Assigned in reverse SATB order to prove ordering is applied, not incidental.
        Capsule::table('user_voice_groups')->insert([
            ['user_id' => 1, 'voice_group_id' => 4, 'sub_voice_id' => null],
            ['user_id' => 1, 'voice_group_id' => 2, 'sub_voice_id' => null],
            ['user_id' => 1, 'voice_group_id' => 1, 'sub_voice_id' => null],
        ]);

        $user = User::with('voiceGroups')->find(1);

        $this->assertSame(
            ['Sopran', 'Alt', 'Bass'],
            $user->voiceGroups->pluck('name')->all()
        );
    }
}
