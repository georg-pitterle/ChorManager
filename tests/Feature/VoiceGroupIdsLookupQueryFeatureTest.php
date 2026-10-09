<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\User;
use App\Models\VoiceGroup;
use App\Queries\ProjectQuery;
use App\Services\NameFormatterService;
use App\Util\PasswordHasher;
use Illuminate\Database\Capsule\Manager as Capsule;
use PHPUnit\Framework\TestCase;
use Tests\Unit\Bootstrap;

/**
 * Die Stimmgruppen-Ids eines Mitglieds kommen in einer Abfrage zurück.
 *
 * Gefragt ist eine Id-Liste, nicht das Konto. Der Lookup lud dafür zuerst das
 * Mitglied und stellte danach die zweite Abfrage über die Beziehung - dieselben
 * zwei Runden zum Server, die ProjectMemberPolicy::loadAccessibleProjectIds()
 * schon hinter sich hat. Er läuft an jedem Zuweisen und Entfernen eines
 * Projektmitglieds, und zwar vor der Rechteprüfung.
 *
 * Die Prüfung auf das vorhandene Konto blieb dabei nötig, weil ein direktes
 * User::find(...)->voiceGroups() bei einem gelöschten Mitglied mit einem Fatal
 * Error abgebrochen wäre. Fragt die Abfrage von der Stimmgruppe aus, fällt der
 * Fall von selbst auf die leere Liste - die Prüfung entfällt mit der Abfrage.
 */
class VoiceGroupIdsLookupQueryFeatureTest extends TestCase
{
    private int $sopranId = 0;
    private int $altId = 0;
    private int $memberId = 0;
    private int $withoutVoiceGroupId = 0;
    private int $deletedUserId = 0;

    protected function setUp(): void
    {
        parent::setUp();
        Bootstrap::setupTestDatabase();
        Bootstrap::getCapsule()?->connection()->beginTransaction();

        $this->sopranId = (int) VoiceGroup::where('name', 'Sopran')->firstOrFail()->id;
        $this->altId = (int) VoiceGroup::where('name', 'Alt')->firstOrFail()->id;

        $this->memberId = $this->createMember('Mira', 'Mehrstimmig', [$this->sopranId, $this->altId]);
        $this->withoutVoiceGroupId = $this->createMember('Olga', 'Ohne', []);

        // Eine Kennung, hinter der kein Konto mehr steht: so sieht eine Sitzung
        // aus, deren Mitglied inzwischen gelöscht wurde.
        $this->deletedUserId = $this->createMember('Gerda', 'Gelöscht', [$this->sopranId]);
        User::destroy($this->deletedUserId);
    }

    protected function tearDown(): void
    {
        $connection = Bootstrap::getCapsule()?->connection();
        if ($connection !== null && $connection->transactionLevel() > 0) {
            $connection->rollBack();
        }

        parent::tearDown();
    }

    /**
     * @param list<int> $voiceGroupIds
     */
    private function createMember(string $firstName, string $lastName, array $voiceGroupIds): int
    {
        $user = User::create([
            'email' => strtolower($lastName) . '_' . bin2hex(random_bytes(4)) . '@example.test',
            'password' => PasswordHasher::hash(bin2hex(random_bytes(8))),
            'first_name' => $firstName,
            'last_name' => $lastName,
            'is_active' => 1,
        ]);

        foreach ($voiceGroupIds as $voiceGroupId) {
            Capsule::table('user_voice_groups')->insert([
                'user_id' => $user->id,
                'voice_group_id' => $voiceGroupId,
                'sub_voice_id' => null,
            ]);
        }

        return (int) $user->id;
    }

    private function query(): ProjectQuery
    {
        return new ProjectQuery(new NameFormatterService());
    }

    /**
     * @return array{ids: list<int>, queries: int}
     */
    private function lookup(int $userId): array
    {
        $connection = Bootstrap::getCapsule()?->connection();
        $this->assertNotNull($connection);

        $connection->flushQueryLog();
        $connection->enableQueryLog();

        try {
            $ids = $this->query()->getUserVoiceGroupIds($userId);
            $queries = $connection->getQueryLog();
        } finally {
            $connection->disableQueryLog();
            $connection->flushQueryLog();
        }

        return ['ids' => $ids, 'queries' => count($queries)];
    }

    public function testTheLookupCostsASingleQuery(): void
    {
        $result = $this->lookup($this->memberId);

        $this->assertSame(
            1,
            $result['queries'],
            'Eine Id-Liste braucht das Konto nicht - die Stimmgruppen allein genügen.'
        );
    }

    public function testTheIdsComeBackAscendingAsIntegers(): void
    {
        $expected = [min($this->sopranId, $this->altId), max($this->sopranId, $this->altId)];

        $this->assertSame($expected, $this->lookup($this->memberId)['ids']);
    }

    public function testAMemberWithoutVoiceGroupsHasAnEmptyList(): void
    {
        $result = $this->lookup($this->withoutVoiceGroupId);

        $this->assertSame([], $result['ids']);
        $this->assertSame(1, $result['queries']);
    }

    /**
     * Ein gelöschtes Mitglied darf nicht durchschlagen: Die leere Liste schließt
     * das stimmgruppen-beschränkte Recht aus, und mehr war hier nie gefragt.
     */
    public function testADeletedMemberHasAnEmptyListWithoutAnError(): void
    {
        $this->assertSame([], $this->lookup($this->deletedUserId)['ids']);
    }

    /**
     * Eine Kennung, die nie ein Konto war, stellt keine Abfrage: Das Ergebnis
     * steht schon fest - dieselbe Abkürzung wie in getProjectsByIds().
     */
    public function testANonPositiveIdCostsNoQuery(): void
    {
        $result = $this->lookup(0);

        $this->assertSame([], $result['ids']);
        $this->assertSame(0, $result['queries']);
    }

    /**
     * Die Beziehung bleibt der Maßstab: Was User::voiceGroups() liefert, liefert
     * auch der Lookup - nur ohne die erste Runde zum Server.
     */
    public function testTheResultMatchesTheRelation(): void
    {
        $viaRelation = User::findOrFail($this->memberId)
            ->voiceGroups()
            ->pluck('voice_groups.id')
            ->map(static fn($id): int => (int) $id)
            ->all();

        $this->assertSame($viaRelation, $this->lookup($this->memberId)['ids']);
    }
}
