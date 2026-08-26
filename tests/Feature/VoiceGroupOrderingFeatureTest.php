<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\User;
use App\Models\VoiceGroup;
use App\Util\VoiceGroupOrder;
use Illuminate\Database\Capsule\Manager as Capsule;
use PHPUnit\Framework\TestCase;
use Tests\Unit\Bootstrap;

/**
 * Locks in the project convention: every listing/iteration over voice groups
 * must be ordered Sopran, Alt, Tenor, Bass (canonical seed id order); sub
 * voices within a group are ordered alphabetically.
 *
 * Die vier kanonischen Stimmgruppen (Sopran, Alt, Tenor, Bass) stammen aus der
 * initialen Migration und sind damit fixer Bestandteil jedes migrierten
 * Schemas, nicht variable Bestandsdaten - deshalb duerfen die folgenden Tests
 * sich direkt auf sie stuetzen, statt sie selbst anzulegen.
 */
class VoiceGroupOrderingFeatureTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        Bootstrap::setupTestDatabase();
        Bootstrap::getCapsule()?->connection()->beginTransaction();
    }

    protected function tearDown(): void
    {
        $connection = Bootstrap::getCapsule()?->connection();
        if ($connection !== null && $connection->transactionLevel() > 0) {
            $connection->rollBack();
        }

        parent::tearDown();
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
        $suffix = bin2hex(random_bytes(4));

        // Eigene Stimmgruppe statt einer kanonischen: die Untergruppen werden
        // bewusst in umgekehrt-alphabetischer Reihenfolge angelegt, um zu
        // beweisen, dass die Relation wirklich sortiert statt zufaellig richtig zu liegen.
        $groupId = (int) Capsule::table('voice_groups')->insertGetId([
            'name' => 'Testgruppe ' . $suffix,
        ]);
        Capsule::table('sub_voices')->insert([
            ['name' => 'Zweite ' . $suffix, 'voice_group_id' => $groupId],
            ['name' => 'Erste ' . $suffix, 'voice_group_id' => $groupId],
        ]);

        $group = VoiceGroup::with('subVoices')->find($groupId);

        $this->assertSame(
            ['Erste ' . $suffix, 'Zweite ' . $suffix],
            $group->subVoices->pluck('name')->all()
        );
    }

    public function testUserVoiceGroupsRelationIsSatbOrder(): void
    {
        $sopranId = (int) VoiceGroup::where('name', 'Sopran')->value('id');
        $altId = (int) VoiceGroup::where('name', 'Alt')->value('id');
        $bassId = (int) VoiceGroup::where('name', 'Bass')->value('id');

        $userId = (int) Capsule::table('users')->insertGetId([
            'email' => 'reihenfolge' . bin2hex(random_bytes(4)) . '@example.test',
            'password' => password_hash('irrelevant', PASSWORD_DEFAULT),
            'first_name' => 'A',
            'last_name' => 'B',
        ]);
        // Assigned in reverse SATB order to prove ordering is applied, not incidental.
        Capsule::table('user_voice_groups')->insert([
            ['user_id' => $userId, 'voice_group_id' => $bassId, 'sub_voice_id' => null],
            ['user_id' => $userId, 'voice_group_id' => $altId, 'sub_voice_id' => null],
            ['user_id' => $userId, 'voice_group_id' => $sopranId, 'sub_voice_id' => null],
        ]);

        $user = User::with('voiceGroups')->find($userId);

        $this->assertSame(
            ['Sopran', 'Alt', 'Bass'],
            $user->voiceGroups->pluck('name')->all()
        );
    }
}
