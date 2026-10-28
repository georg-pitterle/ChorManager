<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\User;
use App\Models\VoiceGroupListColumn;
use App\Models\VoiceGroupListEntry;
use App\Services\VoiceGroupListRows;
use Illuminate\Database\Capsule\Manager as Capsule;
use PHPUnit\Framework\TestCase;
use Tests\Unit\Bootstrap;

/**
 * Welche Mitglieder in einer Stimmgruppen-Liste stehen.
 *
 * Gespeichert wird das nirgends: Zeilen sind die aktiven Mitglieder der
 * Stimmgruppe, mit Projekt nur die Projektmitglieder darunter. Wer herausfällt,
 * behält seine Einträge und hat sie bei der Rückkehr wieder.
 */
final class VoiceGroupListRowsFeatureTest extends TestCase
{
    use VoiceGroupListFixtures;

    private VoiceGroupListRows $rows;

    protected function setUp(): void
    {
        parent::setUp();
        Bootstrap::setupTestDatabase();
        Capsule::connection()->beginTransaction();
        $this->rows = new VoiceGroupListRows();
    }

    protected function tearDown(): void
    {
        $connection = Capsule::connection();
        if ($connection->transactionLevel() > 0) {
            $connection->rollBack();
        }

        parent::tearDown();
    }

    public function testAllSubVoicesOfTheVoiceGroupAreRows(): void
    {
        $group = $this->makeVoiceGroup();
        $first = $this->makeMember($group, $this->makeSubVoice($group, 'Sopran 1'));
        $second = $this->makeMember($group, $this->makeSubVoice($group, 'Sopran 2'));
        $without = $this->makeMember($group);

        $this->assertEqualsCanonicalizing(
            [(int) $first->id, (int) $second->id, (int) $without->id],
            $this->rows->memberIds($this->makeList($group))
        );
    }

    public function testArchivedMembersAreNoRows(): void
    {
        $group = $this->makeVoiceGroup();
        $active = $this->makeMember($group);
        $this->makeMember($group, null, false);

        $this->assertSame([(int) $active->id], $this->rows->memberIds($this->makeList($group)));
    }

    public function testMembersOfOtherVoiceGroupsAreNoRows(): void
    {
        $group = $this->makeVoiceGroup();
        $own = $this->makeMember($group);
        $this->makeMember($this->makeVoiceGroup('Fremd'));
        $this->makeMember(null);

        $list = $this->makeList($group);

        $this->assertSame([(int) $own->id], $this->rows->memberIds($list));
        $this->assertTrue($this->rows->isRow($list, (int) $own->id));
    }

    public function testWithAProjectOnlyProjectMembersAreRows(): void
    {
        $group = $this->makeVoiceGroup();
        $inProject = $this->makeMember($group);
        $notInProject = $this->makeMember($group);
        $list = $this->makeList($group, $this->makeProject([$inProject]));

        $this->assertSame([(int) $inProject->id], $this->rows->memberIds($list));
        $this->assertFalse($this->rows->isRow($list, (int) $notInProject->id));
    }

    public function testAMemberWhoComesBackHasTheirEntriesAgain(): void
    {
        $group = $this->makeVoiceGroup();
        $member = $this->makeMember($group);
        $list = $this->makeList($group);
        $column = $this->makeColumn($list);
        $this->tick($column, $member);

        $member->update(['is_active' => 0]);
        $this->assertSame([], $this->rows->entriesByColumnAndUser($list));
        $this->assertSame(['done' => 0, 'total' => 0], $this->rows->progress($list)[(int) $column->id]);

        $member->update(['is_active' => 1]);
        $entries = $this->rows->entriesByColumnAndUser($list);
        $this->assertArrayHasKey((int) $member->id, $entries[(int) $column->id] ?? []);
    }

    public function testProgressCountsOnlyCurrentRows(): void
    {
        $group = $this->makeVoiceGroup();
        $ticked = $this->makeMember($group);
        $open = $this->makeMember($group);
        $archived = $this->makeMember($group);
        $list = $this->makeList($group);
        $column = $this->makeColumn($list);
        $note = $this->makeColumn($list, VoiceGroupListColumn::TYPE_NOTE, 'Kleidergröße', 1);
        $this->tick($column, $ticked);
        $this->tick($column, $archived);
        $archived->update(['is_active' => 0]);

        $progress = $this->rows->progress($list);

        $this->assertSame(['done' => 1, 'total' => 2], $progress[(int) $column->id]);
        $this->assertArrayNotHasKey((int) $note->id, $progress);
        $this->assertNotNull($open->id);
    }

    public function testAMemberOfTwoVoiceGroupsHasIndependentRowsInBothLists(): void
    {
        $soprano = $this->makeVoiceGroup('Sopran');
        $alto = $this->makeVoiceGroup('Alt');
        $member = $this->makeMember($soprano);
        $member->voiceGroups()->attach($alto->id, ['sub_voice_id' => null]);
        $sopranoList = $this->makeList($soprano);
        $altoList = $this->makeList($alto);
        $sopranoColumn = $this->makeColumn($sopranoList);
        $altoColumn = $this->makeColumn($altoList);

        $this->tick($sopranoColumn, $member);

        $this->assertTrue($this->rows->isRow($sopranoList, (int) $member->id));
        $this->assertTrue($this->rows->isRow($altoList, (int) $member->id));
        $this->assertSame(['done' => 1, 'total' => 1], $this->rows->progress($sopranoList)[(int) $sopranoColumn->id]);
        $this->assertSame(['done' => 0, 'total' => 1], $this->rows->progress($altoList)[(int) $altoColumn->id]);
    }

    public function testRowsAreOrderedBySubVoiceThenName(): void
    {
        $group = $this->makeVoiceGroup();
        $two = $this->makeSubVoice($group, 'Sopran 2');
        $one = $this->makeSubVoice($group, 'Sopran 1');
        $this->makeMember($group, $two, true, 'Adler');
        $this->makeMember($group, null, true, 'Berger');
        $this->makeMember($group, $one, true, 'Zeller');
        $this->makeMember($group, $one, true, 'Auer');

        $rows = $this->rows->members($this->makeList($group));

        $this->assertSame(
            ['Auer', 'Zeller', 'Adler', 'Berger'],
            array_map(static fn(array $row): string => (string) $row['user']->last_name, $rows)
        );
        $this->assertSame(['Sopran 1', 'Sopran 1', 'Sopran 2', null], array_column($rows, 'sub_voice_name'));
    }

    /**
     * Der Fortschritt stellte je Häkchen-Spalte eine eigene COUNT-Abfrage. Bei fünf
     * Spalten waren das fünf Abfragen für eine Ansicht, die sie alle zugleich zeigt.
     */
    public function testProgressTakesOneQueryForAllColumns(): void
    {
        $group = $this->makeVoiceGroup();
        $member = $this->makeMember($group);
        $list = $this->makeList($group);
        $first = $this->makeColumn($list);
        $second = $this->makeColumn($list, VoiceGroupListColumn::TYPE_CHECKBOX, 'Noten da', 1);
        $third = $this->makeColumn($list, VoiceGroupListColumn::TYPE_CHECKBOX, 'Bezahlt', 2);
        $this->makeColumn($list, VoiceGroupListColumn::TYPE_NOTE, 'Kleidergröße', 3);
        $this->tick($second, $member);

        $progress = null;
        $queries = $this->entryQueriesDuring(function () use ($list, &$progress): void {
            $progress = $this->rows->progress($list);
        });

        $this->assertCount(1, $queries, 'Der Fortschritt aller Spalten braucht eine Abfrage.');
        $this->assertSame(['done' => 0, 'total' => 1], $progress[(int) $first->id]);
        $this->assertSame(['done' => 1, 'total' => 1], $progress[(int) $second->id]);
        $this->assertSame(['done' => 0, 'total' => 1], $progress[(int) $third->id]);
    }

    /**
     * Zwei Stellen im Controller brauchten den Stand genau einer Spalte und ließen
     * dafür den aller Spalten berechnen.
     */
    public function testProgressForASingleColumnAsksOnlyForThatColumn(): void
    {
        $group = $this->makeVoiceGroup();
        $ticked = $this->makeMember($group);
        $this->makeMember($group);
        $list = $this->makeList($group);
        $column = $this->makeColumn($list);
        $other = $this->makeColumn($list, VoiceGroupListColumn::TYPE_CHECKBOX, 'Noten da', 1);
        $this->tick($column, $ticked);

        $single = null;
        $queries = $this->entryQueriesDuring(function () use ($list, $column, &$single): void {
            $single = $this->rows->progressFor($list, $column);
        });

        $this->assertSame(['done' => 1, 'total' => 2], $single);
        $this->assertCount(1, $queries);
        // Der Einzelfall zählt eine Spalte, er gruppiert nicht über alle.
        $this->assertStringNotContainsString('group by', strtolower($queries[0]));
        $this->assertNotNull($other->id);
    }

    /**
     * Eine Spalte ohne Häkchen hat keinen Fortschritt - sonst zählte eine
     * Notizspalte Häkchen, die es bei ihr nicht gibt.
     */
    public function testProgressForANoteColumnIsNull(): void
    {
        $group = $this->makeVoiceGroup();
        $this->makeMember($group);
        $list = $this->makeList($group);
        $note = $this->makeColumn($list, VoiceGroupListColumn::TYPE_NOTE, 'Kleidergröße');

        $this->assertNull($this->rows->progressFor($list, $note));
    }

    /**
     * Abfragen gegen die Einträge-Tabelle während eines Aufrufs; die Zeilen- und
     * Spaltenabfragen davor interessieren hier nicht.
     *
     * @return list<string>
     */
    private function entryQueriesDuring(callable $run): array
    {
        $connection = Capsule::connection();
        $connection->flushQueryLog();
        $connection->enableQueryLog();

        try {
            $run();
            $log = $connection->getQueryLog();
        } finally {
            $connection->disableQueryLog();
            $connection->flushQueryLog();
        }

        $queries = [];
        foreach ($log as $entry) {
            $sql = (string) ($entry['query'] ?? '');
            if (!str_contains($sql, '`voice_group_list_entries`')) {
                continue;
            }
            $queries[] = $sql;
        }

        return $queries;
    }

    private function tick(VoiceGroupListColumn $column, User $member): VoiceGroupListEntry
    {
        return VoiceGroupListEntry::create([
            'column_id' => $column->id,
            'user_id' => $member->id,
            'checked_at' => '2026-10-26 10:00:00',
        ]);
    }
}
