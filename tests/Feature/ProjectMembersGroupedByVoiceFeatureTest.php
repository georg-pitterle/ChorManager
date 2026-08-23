<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Queries\ProjectQuery;
use App\Services\NameFormatterService;
use Illuminate\Database\Capsule\Manager as Capsule;
use Illuminate\Database\Schema\Blueprint;
use PHPUnit\Framework\TestCase;

/**
 * Deckt die Gruppierung der Projektbesetzung nach Stimmgruppe und Teilstimme ab.
 * Die Methode wurde bisher nur gemockt - Reihenfolge, Teilstimmen-Auflösung und
 * der Ausschluss archivierter Mitglieder waren damit ungeprüft.
 */
class ProjectMembersGroupedByVoiceFeatureTest extends TestCase
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
            $table->string('first_name')->nullable();
            $table->string('last_name')->nullable();
            $table->boolean('is_active')->default(true);
        });
        $schema->create('projects', function (Blueprint $table): void {
            $table->increments('id');
            $table->string('name');
        });
        $schema->create('project_users', function (Blueprint $table): void {
            $table->integer('user_id');
            $table->integer('project_id');
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

        // Kanonische Reihenfolge kommt aus der id-Reihenfolge der Stimmgruppen.
        Capsule::table('voice_groups')->insert([
            ['id' => 1, 'name' => 'Sopran'],
            ['id' => 2, 'name' => 'Alt'],
        ]);
        Capsule::table('sub_voices')->insert([
            ['id' => 1, 'voice_group_id' => 1, 'name' => 'Sopran 2'],
            ['id' => 2, 'voice_group_id' => 1, 'name' => 'Sopran 1'],
        ]);
        Capsule::table('projects')->insert([
            ['id' => 10, 'name' => 'Adventkonzert'],
        ]);
        Capsule::table('users')->insert([
            ['id' => 1, 'email' => 's1@example.test', 'first_name' => 'Anna', 'last_name' => 'Alt', 'is_active' => 1],
            ['id' => 2, 'email' => 's2@example.test', 'first_name' => 'Berta', 'last_name' => 'Bass', 'is_active' => 1],
            ['id' => 3, 'email' => 'a1@example.test', 'first_name' => 'Clara', 'last_name' => 'Chor', 'is_active' => 1],
            ['id' => 4, 'email' => 'x1@example.test', 'first_name' => 'Dora', 'last_name' => 'Dunkel', 'is_active' => 1],
            ['id' => 5, 'email' => 'old@example.test', 'first_name' => 'Emil', 'last_name' => 'Ehemalig', 'is_active' => 0],
        ]);
        Capsule::table('project_users')->insert([
            ['user_id' => 1, 'project_id' => 10],
            ['user_id' => 2, 'project_id' => 10],
            ['user_id' => 3, 'project_id' => 10],
            ['user_id' => 4, 'project_id' => 10],
            ['user_id' => 5, 'project_id' => 10],
        ]);
        Capsule::table('user_voice_groups')->insert([
            ['user_id' => 1, 'voice_group_id' => 1, 'sub_voice_id' => 1],
            ['user_id' => 2, 'voice_group_id' => 1, 'sub_voice_id' => 2],
            ['user_id' => 3, 'voice_group_id' => 2, 'sub_voice_id' => null],
            ['user_id' => 5, 'voice_group_id' => 1, 'sub_voice_id' => 1],
        ]);
    }

    public function testGroupsMembersByVoiceGroupAndSubVoice(): void
    {
        $grouped = (new ProjectQuery(new NameFormatterService()))
            ->getProjectMembersGroupedByVoice(10);

        $this->assertSame(
            ['Sopran', 'Alt', '_ohne_stimmgruppe'],
            array_keys($grouped),
            'Stimmgruppen folgen der kanonischen Reihenfolge, "ohne Stimmgruppe" steht zuletzt.'
        );

        $this->assertSame(
            ['Sopran 1', 'Sopran 2'],
            array_keys($grouped['Sopran']),
            'Teilstimmen werden innerhalb der Stimmgruppe alphabetisch sortiert.'
        );

        $this->assertSame([2], array_column($grouped['Sopran']['Sopran 1'], 'id'));
        $this->assertSame([1], array_column($grouped['Sopran']['Sopran 2'], 'id'));
        $this->assertSame('Sopran', $grouped['Sopran']['Sopran 1'][0]['voice_group_name']);
        $this->assertSame('Sopran 1', $grouped['Sopran']['Sopran 1'][0]['sub_voice_name']);
    }

    public function testMembersWithoutVoiceGroupOrSubVoiceUseThePlaceholderBuckets(): void
    {
        $grouped = (new ProjectQuery(new NameFormatterService()))
            ->getProjectMembersGroupedByVoice(10);

        $this->assertSame([3], array_column($grouped['Alt']['_ohne_teilstimme'], 'id'));
        $this->assertNull($grouped['Alt']['_ohne_teilstimme'][0]['sub_voice_name']);

        $ungrouped = $grouped['_ohne_stimmgruppe']['_ohne_teilstimme'];
        $this->assertSame([4], array_column($ungrouped, 'id'));
        $this->assertNull($ungrouped[0]['voice_group_name']);
        $this->assertNull($ungrouped[0]['sub_voice_name']);
    }

    public function testArchivedMembersAreExcluded(): void
    {
        $grouped = (new ProjectQuery(new NameFormatterService()))
            ->getProjectMembersGroupedByVoice(10);

        $ids = [];
        foreach ($grouped as $subVoices) {
            foreach ($subVoices as $members) {
                $ids = array_merge($ids, array_column($members, 'id'));
            }
        }

        $this->assertNotContains(5, $ids, 'Archivierte Mitglieder gehören nicht in die Besetzung.');
        $this->assertCount(4, $ids);
    }

    public function testUnknownProjectYieldsAnEmptyGrouping(): void
    {
        $grouped = (new ProjectQuery(new NameFormatterService()))
            ->getProjectMembersGroupedByVoice(999);

        $this->assertSame([], $grouped);
    }
}
