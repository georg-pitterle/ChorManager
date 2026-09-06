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
 * Die Teilstimmen der Projektbesetzung stehen in derselben Reihenfolge wie in
 * jeder anderen Auflistung: der, die die Datenbank über `sub_voices.name` liefert
 * (Kollation utf8mb4_general_ci - Groß und klein spielt keine Rolle, Umlaute
 * stehen bei ihrem Grundbuchstaben).
 *
 * Vorher sortierte die Gruppierung per ksort() nach Bytefolge. Damit standen
 * Großbuchstaben vor Kleinbuchstaben und Umlaute hinter dem Z - dieselben
 * Teilstimmen erschienen je nach Seite in anderer Reihenfolge.
 */
class SubVoiceOrderingFeatureTest extends TestCase
{
    private int $projectId = 0;
    private int $voiceGroupId = 0;

    /** @var list<string> */
    private array $subVoiceNames = [];

    /** @var list<int> */
    private array $subVoiceIds = [];

    protected function setUp(): void
    {
        parent::setUp();
        Bootstrap::setupTestDatabase();
        Bootstrap::getCapsule()?->connection()->beginTransaction();

        $voiceGroup = VoiceGroup::where('name', 'Tenor')->firstOrFail();
        $this->voiceGroupId = (int) $voiceGroup->id;

        $project = Project::create(['name' => 'Sortierprobe ' . bin2hex(random_bytes(4))]);
        $this->projectId = (int) $project->id;

        // Namen, an denen sich Bytefolge und Datenbank-Kollation vollständig
        // widersprechen: per Bytefolge stehen Großbuchstaben vorn, Kleinbuchstaben
        // dahinter und der Umlaut ganz zuletzt. Die Datenbank sortiert genau
        // umgekehrt - "Ä" bei "A", Groß und klein gleichwertig.
        $names = ['Ätherische Lage', 'innige Lage', 'Klare Lage', 'Tiefe Lage'];

        foreach ($names as $index => $name) {
            $subVoice = SubVoice::create(['name' => $name, 'voice_group_id' => $this->voiceGroupId]);
            $this->subVoiceNames[] = $name;
            $this->subVoiceIds[] = (int) $subVoice->id;

            $user = User::create([
                'email' => 'sortier' . $index . '_' . bin2hex(random_bytes(4)) . '@example.test',
                'password' => password_hash(bin2hex(random_bytes(8)), PASSWORD_BCRYPT),
                'first_name' => 'Tobias',
                'last_name' => 'Tenor ' . $index,
                'is_active' => 1,
            ]);

            Capsule::table('project_users')->insert([
                'user_id' => $user->id,
                'project_id' => $this->projectId,
            ]);
            Capsule::table('user_voice_groups')->insert([
                'user_id' => $user->id,
                'voice_group_id' => $this->voiceGroupId,
                'sub_voice_id' => $subVoice->id,
            ]);
        }

        // Ein Mitglied ohne Teilstimme: sein Sammelschlüssel muss zuletzt stehen.
        $withoutSubVoice = User::create([
            'email' => 'ohneteil_' . bin2hex(random_bytes(4)) . '@example.test',
            'password' => password_hash(bin2hex(random_bytes(8)), PASSWORD_BCRYPT),
            'first_name' => 'Otto',
            'last_name' => 'Ohne',
            'is_active' => 1,
        ]);
        Capsule::table('project_users')->insert([
            'user_id' => $withoutSubVoice->id,
            'project_id' => $this->projectId,
        ]);
        Capsule::table('user_voice_groups')->insert([
            'user_id' => $withoutSubVoice->id,
            'voice_group_id' => $this->voiceGroupId,
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

    public function testSubVoicesFollowTheDatabaseOrder(): void
    {
        $grouped = (new ProjectQuery(new NameFormatterService()))
            ->getProjectMembersGroupedByVoice($this->projectId);

        $actual = array_keys($grouped['Tenor']);
        $this->assertSame(ProjectQuery::NO_SUB_VOICE_KEY, array_pop($actual), 'Ohne Teilstimme steht zuletzt.');

        $this->assertSame($this->databaseOrder(), $actual);
    }

    public function testByteOrderWouldHaveSortedDifferently(): void
    {
        // Ohne diesen Nachweis liefe der Test oben auch dann grün, wenn beide
        // Reihenfolgen zufällig übereinstimmten - und würde nichts absichern.
        $byteOrder = $this->subVoiceNames;
        sort($byteOrder, SORT_STRING);

        $this->assertNotSame(
            $byteOrder,
            $this->databaseOrder(),
            'Die Namen müssen sich in Bytefolge und Kollation unterscheiden, sonst prüft der Test nichts.'
        );
    }

    /**
     * Die Reihenfolge, die die Datenbank selbst liefert - eingegrenzt auf die hier
     * angelegten Teilstimmen, damit die aus den Migrationen nicht hineinspielen.
     *
     * @return list<string>
     */
    private function databaseOrder(): array
    {
        return SubVoice::whereIn('id', $this->subVoiceIds)
            ->orderBy('name')
            ->pluck('name')
            ->all();
    }
}
