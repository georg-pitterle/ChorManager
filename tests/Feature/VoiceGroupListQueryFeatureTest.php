<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\Project;
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

    /**
     * Die Projektauswahl einer Liste steht in derselben Reihenfolge wie jede andere
     * Projektliste - zuletzt gestartet oben, bei gleichem Datum nach Name. Geprüft
     * wird gegen Project::scopeChronological() selbst: Schreibt die Abfrage die
     * Reihenfolge ab, statt den Scope zu rufen, laufen die beiden auseinander, sobald
     * eine Seite geändert wird.
     */
    public function testTheProjectChoiceFollowsTheSharedProjectOrder(): void
    {
        $group = $this->makeVoiceGroup();
        $member = $this->makeMember($group);

        $early = $this->makeProject([$member]);
        $early->update(['name' => 'B-Projekt', 'start_date' => '2027-01-01']);
        $late = $this->makeProject([$member]);
        $late->update(['name' => 'A-Projekt', 'start_date' => '2027-09-01']);
        $sameDay = $this->makeProject([$member]);
        $sameDay->update(['name' => 'A-Projekt zuerst', 'start_date' => '2027-09-01']);

        $ids = [(int) $early->id, (int) $late->id, (int) $sameDay->id];

        $actual = (new VoiceGroupListQuery())->allowedProjects([(int) $group->id])
            ->pluck('id')
            ->map(static fn($id): int => (int) $id)
            ->filter(static fn(int $id): bool => in_array($id, $ids, true))
            ->values()
            ->all();
        $expected = Project::query()->whereIn('projects.id', $ids)->chronological()
            ->pluck('id')
            ->map(static fn($id): int => (int) $id)
            ->all();

        self::assertSame($expected, $actual);
    }

    /**
     * Das bisherige Projekt einer Liste bleibt wählbar, auch wenn kein Mitglied der
     * Stimmgruppe mehr darin mitwirkt - sonst verlöre eine Bearbeitung den Bezug.
     */
    public function testTheCurrentProjectStaysSelectableWithoutAnyMemberLeft(): void
    {
        $group = $this->makeVoiceGroup();
        $this->makeMember($group);
        $foreign = $this->makeProject();

        $withoutIt = (new VoiceGroupListQuery())->allowedProjects([(int) $group->id])
            ->pluck('id')
            ->map(static fn($id): int => (int) $id)
            ->all();
        $withIt = (new VoiceGroupListQuery())->allowedProjects([(int) $group->id], (int) $foreign->id)
            ->pluck('id')
            ->map(static fn($id): int => (int) $id)
            ->all();

        self::assertNotContains((int) $foreign->id, $withoutIt);
        self::assertContains((int) $foreign->id, $withIt);
    }
}
