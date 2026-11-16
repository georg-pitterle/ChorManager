<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\SubVoice;
use App\Models\User;
use App\Models\VoiceGroup;
use App\Queries\VoiceGroupQuery;
use App\Util\PasswordHasher;
use Illuminate\Database\Capsule\Manager as Capsule;
use PHPUnit\Framework\TestCase;
use Tests\Unit\Bootstrap;

/**
 * Die Mitgliederzahlen je Stimmgruppe und Unterstimme stehen in der Abfrageschicht.
 *
 * Gezählt wird in zwei Abfragen für alle Gruppen, nicht je Gruppe eine; die Zahlen
 * kamen aus dem VoiceGroupController, der dafür die Datenbank selbst abfragte.
 */
final class VoiceGroupQueryFeatureTest extends TestCase
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

    private function member(): User
    {
        return User::create([
            'first_name' => 'Gruppen',
            'last_name' => 'Mitglied',
            'email' => 'mitglied.' . bin2hex(random_bytes(4)) . '@example.test',
            'password' => PasswordHasher::hash('irrelevant'),
            'is_active' => 1,
        ]);
    }

    public function testMembersAreCountedPerGroupAndPerSubVoice(): void
    {
        $group = VoiceGroup::create(['name' => 'Zähltest ' . bin2hex(random_bytes(3))]);
        $sub = SubVoice::create(['name' => 'Zähltest 1', 'voice_group_id' => $group->id]);
        $this->member()->voiceGroups()->attach($group->id, ['sub_voice_id' => $sub->id]);
        $this->member()->voiceGroups()->attach($group->id, ['sub_voice_id' => null]);

        $counts = (new VoiceGroupQuery())->memberCounts();

        self::assertSame(2, $counts['groups'][(int) $group->id]);
        self::assertSame(1, $counts['sub_voices'][(int) $sub->id]);
    }

    public function testAGroupWithoutMembersHasNoEntry(): void
    {
        $group = VoiceGroup::create(['name' => 'Leer ' . bin2hex(random_bytes(3))]);

        $counts = (new VoiceGroupQuery())->memberCounts();

        self::assertArrayNotHasKey((int) $group->id, $counts['groups']);
    }
}
