<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\Project;
use App\Models\SubVoice;
use App\Models\User;
use App\Models\VoiceGroup;
use App\Queries\ProjectQuery;
use App\Services\NameFormatterService;
use Illuminate\Database\Capsule\Manager as Capsule;
use PHPUnit\Framework\TestCase;
use Tests\Unit\Bootstrap;

/**
 * Deckt die Gruppierung der Projektbesetzung nach Stimmgruppe und Teilstimme ab.
 * Die Methode wurde bisher nur gemockt - Reihenfolge, Teilstimmen-Auflösung und
 * der Ausschluss archivierter Mitglieder waren damit ungeprüft.
 */
class ProjectMembersGroupedByVoiceFeatureTest extends TestCase
{
    private int $projectId = 0;

    /** @var array<string, int> */
    private array $userIds = [];

    protected function setUp(): void
    {
        parent::setUp();
        Bootstrap::setupTestDatabase();
        Bootstrap::getCapsule()?->connection()->beginTransaction();

        // Stimmgruppen und Teilstimmen legt die Initial-Migration an; sie sind also auch auf
        // einer frisch migrierten, leeren Datenbank vorhanden. Die kanonische Reihenfolge
        // Sopran vor Alt kommt aus deren Kennungen - genau das prüft der erste Testfall.
        $sopran = VoiceGroup::where('name', 'Sopran')->firstOrFail();
        $alt = VoiceGroup::where('name', 'Alt')->firstOrFail();
        $sopran1 = SubVoice::where('voice_group_id', $sopran->id)->where('name', 'Sopran 1')->firstOrFail();
        $sopran2 = SubVoice::where('voice_group_id', $sopran->id)->where('name', 'Sopran 2')->firstOrFail();

        // Zusätzliche Teilstimme mit höherer Kennung, aber alphabetisch vorderem Namen. Das
        // vorherige Mini-Schema hatte die Teilstimmen dafür verdreht angelegt; gegen die echten
        // Daten fielen Kennungs- und alphabetische Reihenfolge sonst zusammen, und der Nachweis,
        // dass alphabetisch sortiert wird, wäre stillschweigend verloren gegangen.
        $sopran0 = SubVoice::create(['name' => 'Sopran 0', 'voice_group_id' => $sopran->id]);

        $project = Project::create(['name' => 'Adventkonzert ' . bin2hex(random_bytes(4))]);
        $this->projectId = (int) $project->id;

        $members = [
            'annaSopran2' => ['Anna', 'Alt', 1, $sopran->id, $sopran2->id],
            'bertaSopran1' => ['Berta', 'Bass', 1, $sopran->id, $sopran1->id],
            'claraAlt' => ['Clara', 'Chor', 1, $alt->id, null],
            'doraOhne' => ['Dora', 'Dunkel', 1, null, null],
            'emilArchiviert' => ['Emil', 'Ehemalig', 0, $sopran->id, $sopran2->id],
            'friedaDoppelt' => ['Frieda', 'Doppel', 1, $sopran->id, $sopran1->id],
            'gustiSopran0' => ['Gusti', 'Vorne', 1, $sopran->id, $sopran0->id],
        ];

        foreach ($members as $key => [$firstName, $lastName, $isActive, $voiceGroupId, $subVoiceId]) {
            $user = User::create([
                'email' => strtolower($key) . '_' . bin2hex(random_bytes(4)) . '@example.test',
                'password' => password_hash('secret', PASSWORD_BCRYPT),
                'first_name' => $firstName,
                'last_name' => $lastName,
                'is_active' => $isActive,
            ]);
            $this->userIds[$key] = (int) $user->id;

            Capsule::table('project_users')->insert([
                'user_id' => $user->id,
                'project_id' => $this->projectId,
            ]);

            if ($voiceGroupId !== null) {
                Capsule::table('user_voice_groups')->insert([
                    'user_id' => $user->id,
                    'voice_group_id' => $voiceGroupId,
                    'sub_voice_id' => $subVoiceId,
                ]);
            }
        }

        // Frieda singt in zwei Stimmgruppen - Sopran ist die erste.
        Capsule::table('user_voice_groups')->insert([
            'user_id' => $this->userIds['friedaDoppelt'],
            'voice_group_id' => $alt->id,
            'sub_voice_id' => null,
        ]);
    }

    protected function tearDown(): void
    {
        $connection = Bootstrap::getCapsule()?->connection();
        if ($connection !== null && $connection->transactionLevel() > 0) {
            $connection->rollBack();
        }

        parent::tearDown();
    }

    private function id(string $key): int
    {
        return $this->userIds[$key];
    }

    private function sopranId(): int
    {
        return (int) VoiceGroup::where('name', 'Sopran')->firstOrFail()->id;
    }

    private function altId(): int
    {
        return (int) VoiceGroup::where('name', 'Alt')->firstOrFail()->id;
    }

    /**
     * @return list<int>
     */
    private function idsIn(array $grouped): array
    {
        $ids = [];
        foreach ($grouped as $subVoices) {
            foreach ($subVoices as $members) {
                $ids = array_merge($ids, array_column($members, 'id'));
            }
        }

        return $ids;
    }

    public function testGroupsMembersByVoiceGroupAndSubVoice(): void
    {
        $grouped = (new ProjectQuery(new NameFormatterService()))
            ->getProjectMembersGroupedByVoice($this->projectId);

        $this->assertSame(
            ['Sopran', 'Alt', '_ohne_stimmgruppe'],
            array_keys($grouped),
            'Stimmgruppen folgen der kanonischen Reihenfolge, "ohne Stimmgruppe" steht zuletzt.'
        );

        // "Sopran 0" hat die höchste Kennung und steht trotzdem vorn - der Beweis, dass
        // alphabetisch und nicht nach Kennung sortiert wird.
        $this->assertSame(
            ['Sopran 0', 'Sopran 1', 'Sopran 2'],
            array_keys($grouped['Sopran']),
            'Teilstimmen werden innerhalb der Stimmgruppe alphabetisch sortiert.'
        );

        $this->assertSame(
            [$this->id('bertaSopran1'), $this->id('friedaDoppelt')],
            array_column($grouped['Sopran']['Sopran 1'], 'id')
        );
        $this->assertSame([$this->id('annaSopran2')], array_column($grouped['Sopran']['Sopran 2'], 'id'));
        $this->assertSame('Sopran', $grouped['Sopran']['Sopran 1'][0]['voice_group_name']);
        $this->assertSame('Sopran 1', $grouped['Sopran']['Sopran 1'][0]['sub_voice_name']);
    }

    public function testMemberOfSeveralVoiceGroupsAppearsOnlyInTheFirstOne(): void
    {
        $grouped = (new ProjectQuery(new NameFormatterService()))
            ->getProjectMembersGroupedByVoice($this->projectId);

        $this->assertContains($this->id('friedaDoppelt'), array_column($grouped['Sopran']['Sopran 1'], 'id'));
        $this->assertNotContains(
            $this->id('friedaDoppelt'),
            $this->idsIn(['Alt' => $grouped['Alt']]),
            'Ein Mitglied erscheint genau einmal, in seiner ersten Stimmgruppe.'
        );
        $frieda = $this->id('friedaDoppelt');
        $occurrences = count(array_filter($this->idsIn($grouped), static fn($id): bool => $id === $frieda));
        $this->assertSame(1, $occurrences, 'Frieda darf in der Besetzung nicht doppelt gezählt werden.');
    }

    public function testFilterGroupsAMemberUnderTheMatchingVoiceGroup(): void
    {
        $grouped = (new ProjectQuery(new NameFormatterService()))
            ->getProjectMembersGroupedByVoice($this->projectId, [$this->altId()]);

        $this->assertSame(['Alt'], array_keys($grouped), 'Der Filter lässt nur Alt übrig.');
        $this->assertSame(
            [$this->id('claraAlt'), $this->id('friedaDoppelt')],
            $this->idsIn($grouped),
            'Frieda fällt nur über ihre zweite Stimmgruppe in den Filter und gehört deshalb unter Alt.'
        );
        $this->assertSame('Alt', $grouped['Alt']['_ohne_teilstimme'][0]['voice_group_name']);
    }

    public function testFilterKeepsMembersWhoseFirstVoiceGroupMatches(): void
    {
        $grouped = (new ProjectQuery(new NameFormatterService()))
            ->getProjectMembersGroupedByVoice($this->projectId, [$this->sopranId()]);

        $this->assertSame(['Sopran'], array_keys($grouped));
        // Reihenfolge folgt den Teilstimmen-Buckets: "Sopran 0", dann "Sopran 1", dann "Sopran 2".
        $this->assertSame(
            [
                $this->id('gustiSopran0'),
                $this->id('bertaSopran1'),
                $this->id('friedaDoppelt'),
                $this->id('annaSopran2'),
            ],
            $this->idsIn($grouped)
        );
    }

    public function testEmptyFilterYieldsNoMembers(): void
    {
        $grouped = (new ProjectQuery(new NameFormatterService()))
            ->getProjectMembersGroupedByVoice($this->projectId, []);

        $this->assertSame(
            [],
            $grouped,
            'Eine leere Stimmgruppen-Liste schränkt auf nichts ein und darf nicht als "kein Filter" gelten.'
        );
    }

    public function testMembersWithoutVoiceGroupOrSubVoiceUseThePlaceholderBuckets(): void
    {
        $grouped = (new ProjectQuery(new NameFormatterService()))
            ->getProjectMembersGroupedByVoice($this->projectId);

        $this->assertSame([$this->id('claraAlt')], array_column($grouped['Alt']['_ohne_teilstimme'], 'id'));
        $this->assertNull($grouped['Alt']['_ohne_teilstimme'][0]['sub_voice_name']);

        $ungrouped = $grouped['_ohne_stimmgruppe']['_ohne_teilstimme'];
        $this->assertSame([$this->id('doraOhne')], array_column($ungrouped, 'id'));
        $this->assertNull($ungrouped[0]['voice_group_name']);
        $this->assertNull($ungrouped[0]['sub_voice_name']);
    }

    public function testArchivedMembersAreExcluded(): void
    {
        $grouped = (new ProjectQuery(new NameFormatterService()))
            ->getProjectMembersGroupedByVoice($this->projectId);

        $ids = $this->idsIn($grouped);

        $this->assertNotContains(
            $this->id('emilArchiviert'),
            $ids,
            'Archivierte Mitglieder gehören nicht in die Besetzung.'
        );
        $this->assertCount(6, $ids);
    }

    public function testUnknownProjectYieldsAnEmptyGrouping(): void
    {
        $grouped = (new ProjectQuery(new NameFormatterService()))
            ->getProjectMembersGroupedByVoice($this->projectId + 100000);

        $this->assertSame([], $grouped);
    }
}
