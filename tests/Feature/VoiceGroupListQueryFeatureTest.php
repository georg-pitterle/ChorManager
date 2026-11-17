<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\VoiceGroup;
use App\Models\VoiceGroupList;
use App\Queries\VoiceGroupListQuery;
use Illuminate\Database\Capsule\Manager as Capsule;
use PHPUnit\Framework\TestCase;
use Tests\Unit\Bootstrap;

/**
 * Die Abfragen der Stimmgruppenlisten stehen in der Abfrageschicht.
 *
 * Die Übersicht zeigt nur Listen der eigenen Stimmgruppen, nach Titel sortiert; ein
 * leerer Kreis von Stimmgruppen ergibt ohne Abfrage eine leere Antwort.
 */
final class VoiceGroupListQueryFeatureTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        Bootstrap::setupTestDatabase();
        Capsule::connection()->beginTransaction();
    }

    protected function tearDown(): void
    {
        $connection = Capsule::connection();
        if ($connection->transactionLevel() > 0) {
            $connection->rollBack();
        }

        parent::tearDown();
    }

    public function testListsAreSortedByTitleAndScopedToTheGroups(): void
    {
        $suffix = bin2hex(random_bytes(3));
        $own = VoiceGroup::create(['name' => 'Eigene ' . $suffix]);
        $foreign = VoiceGroup::create(['name' => 'Fremde ' . $suffix]);
        VoiceGroupList::create(['voice_group_id' => $own->id, 'title' => 'Beta']);
        VoiceGroupList::create(['voice_group_id' => $own->id, 'title' => 'Alpha']);
        VoiceGroupList::create(['voice_group_id' => $foreign->id, 'title' => 'Fremd']);

        $lists = (new VoiceGroupListQuery())->listsForVoiceGroups([(int) $own->id]);

        self::assertSame(['Alpha', 'Beta'], $lists->pluck('title')->all());
    }

    public function testNoGroupsMeansNoLists(): void
    {
        self::assertCount(0, (new VoiceGroupListQuery())->listsForVoiceGroups([]));
        self::assertSame([], (new VoiceGroupListQuery())->voiceGroupsByName([]));
    }

    public function testVoiceGroupsAreKeyedByName(): void
    {
        $group = VoiceGroup::create(['name' => 'Schlüssel ' . bin2hex(random_bytes(3))]);

        $byName = (new VoiceGroupListQuery())->voiceGroupsByName([(int) $group->id]);

        self::assertSame([$group->name], array_keys($byName));
    }
}
