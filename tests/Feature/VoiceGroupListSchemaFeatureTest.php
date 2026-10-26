<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\VoiceGroupList;
use App\Models\VoiceGroupListColumn;
use App\Models\VoiceGroupListEntry;
use Illuminate\Database\Capsule\Manager as Capsule;
use Illuminate\Database\QueryException;
use PHPUnit\Framework\TestCase;
use Tests\Unit\Bootstrap;

/**
 * Tabellen der Stimmgruppen-Listen: was beim Löschen mitgeht und was bleibt.
 *
 * Die Einträge sind Notizen über Personen. Sie verschwinden mit der Spalte,
 * der Liste oder dem Mitglied - eine Liste überlebt aber das Löschen ihres
 * Projekts, damit die Notizen der Stimmvertretung nicht still mitgehen.
 */
final class VoiceGroupListSchemaFeatureTest extends TestCase
{
    use VoiceGroupListFixtures;

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

    public function testDeletingAColumnDeletesItsEntries(): void
    {
        $group = $this->makeVoiceGroup();
        $member = $this->makeMember($group);
        $column = $this->makeColumn($this->makeList($group));
        $entry = $this->tick($column, (int) $member->id);

        $column->delete();

        $this->assertNull(VoiceGroupListEntry::find($entry->id));
    }

    public function testDeletingAListDeletesColumnsAndEntries(): void
    {
        $group = $this->makeVoiceGroup();
        $member = $this->makeMember($group);
        $list = $this->makeList($group);
        $column = $this->makeColumn($list);
        $entry = $this->tick($column, (int) $member->id);

        $list->delete();

        $this->assertNull(VoiceGroupListColumn::find($column->id));
        $this->assertNull(VoiceGroupListEntry::find($entry->id));
    }

    public function testDeletingAMemberDeletesTheirEntries(): void
    {
        $group = $this->makeVoiceGroup();
        $member = $this->makeMember($group);
        $entry = $this->tick($this->makeColumn($this->makeList($group)), (int) $member->id);

        $member->delete();

        $this->assertNull(VoiceGroupListEntry::find($entry->id));
    }

    public function testDeletingTheProjectKeepsTheListWithoutProject(): void
    {
        $group = $this->makeVoiceGroup();
        $member = $this->makeMember($group);
        $project = $this->makeProject([$member]);
        $list = $this->makeList($group, $project);
        $entry = $this->tick($this->makeColumn($list), (int) $member->id);

        $project->delete();

        $fresh = VoiceGroupList::find($list->id);
        $this->assertNotNull($fresh);
        $this->assertNull($fresh->project_id);
        $this->assertNotNull(VoiceGroupListEntry::find($entry->id));
    }

    public function testAMemberHasAtMostOneEntryPerColumn(): void
    {
        $group = $this->makeVoiceGroup();
        $member = $this->makeMember($group);
        $column = $this->makeColumn($this->makeList($group));
        $this->tick($column, (int) $member->id);

        $this->expectException(QueryException::class);
        $this->tick($column, (int) $member->id);
    }

    public function testColumnsComeInSortOrder(): void
    {
        $list = $this->makeList($this->makeVoiceGroup());
        $this->makeColumn($list, VoiceGroupListColumn::TYPE_NOTE, 'Kleidergröße', 2);
        $this->makeColumn($list, VoiceGroupListColumn::TYPE_CHECKBOX, 'Beitrag bezahlt', 1);

        $this->assertSame(['Beitrag bezahlt', 'Kleidergröße'], $list->columns()->pluck('label')->all());
    }

    private function tick(VoiceGroupListColumn $column, int $userId): VoiceGroupListEntry
    {
        return VoiceGroupListEntry::create([
            'column_id' => $column->id,
            'user_id' => $userId,
            'checked_at' => '2026-10-26 10:00:00',
        ]);
    }
}
