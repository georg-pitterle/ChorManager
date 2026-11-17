<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\User;
use App\Models\VoiceGroup;
use App\Models\VoiceGroupList;
use App\Models\VoiceGroupListColumn;
use App\Models\VoiceGroupListEntry;
use App\Persistence\VoiceGroupListPersistence;
use App\Util\PasswordHasher;
use Illuminate\Database\Capsule\Manager as Capsule;
use PHPUnit\Framework\TestCase;
use Tests\Unit\Bootstrap;

/**
 * Das Schreiben der Stimmgruppenlisten steht in der Persistenz, nicht im Controller.
 *
 * Die Regeln darin - Spalten werden neu durchnummeriert statt getauscht, ein schon
 * gesetztes Häkchen bleibt, wie es ist, ein leerer Eintrag verschwindet - galten
 * schon im VoiceGroupListController und stehen jetzt ohne ihn prüfbar da.
 */
final class VoiceGroupListPersistenceFeatureTest extends TestCase
{
    private VoiceGroupListPersistence $persistence;
    private VoiceGroupList $list;

    protected function setUp(): void
    {
        parent::setUp();
        Bootstrap::setupTestDatabase();
        Capsule::connection()->beginTransaction();

        $this->persistence = new VoiceGroupListPersistence();
        $group = VoiceGroup::create(['name' => 'Persistenz ' . bin2hex(random_bytes(3))]);
        $this->list = $this->persistence->createList((int) $group->id, 'Testliste', null, null);
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
            'first_name' => 'Listen',
            'last_name' => 'Mitglied',
            'email' => 'liste.' . bin2hex(random_bytes(4)) . '@example.test',
            'password' => PasswordHasher::hash('irrelevant'),
            'is_active' => 1,
        ]);
    }

    private function column(string $label, string $type, int $sortOrder): VoiceGroupListColumn
    {
        return VoiceGroupListColumn::create([
            'list_id' => $this->list->id,
            'label' => $label,
            'type' => $type,
            'sort_order' => $sortOrder,
        ]);
    }

    public function testRenumberingSeparatesEqualSortOrders(): void
    {
        $a = $this->column('A', VoiceGroupListColumn::TYPE_NOTE, 0);
        $b = $this->column('B', VoiceGroupListColumn::TYPE_NOTE, 0);
        $c = $this->column('C', VoiceGroupListColumn::TYPE_NOTE, 1);

        $this->persistence->renumberColumns([$c, $a, $b]);

        self::assertSame(0, (int) $c->fresh()->sort_order);
        self::assertSame(1, (int) $a->fresh()->sort_order);
        self::assertSame(2, (int) $b->fresh()->sort_order);
    }

    public function testASecondTickKeepsTheFirstCheck(): void
    {
        $column = $this->column('Haken', VoiceGroupListColumn::TYPE_CHECKBOX, 0);
        $member = $this->member();
        $first = $this->member();
        $second = $this->member();

        $this->persistence->storeEntry($column, (int) $member->id, true, (int) $first->id);
        $before = VoiceGroupListEntry::where('column_id', $column->id)->firstOrFail();
        $this->persistence->storeEntry($column, (int) $member->id, true, (int) $second->id);
        $after = VoiceGroupListEntry::where('column_id', $column->id)->firstOrFail();

        self::assertEquals($before->checked_at, $after->checked_at);
        self::assertSame((int) $first->id, (int) $after->updated_by_user_id);
    }

    public function testAnEmptyValueRemovesTheEntry(): void
    {
        $column = $this->column('Notiz', VoiceGroupListColumn::TYPE_NOTE, 0);
        $member = $this->member();
        $this->persistence->storeEntry($column, (int) $member->id, 'x', null);

        $result = $this->persistence->storeEntry($column, (int) $member->id, '', null);

        self::assertNull($result);
        self::assertSame(0, VoiceGroupListEntry::where('column_id', $column->id)->count());
    }

    public function testANewColumnGoesToTheEnd(): void
    {
        $this->column('Eins', VoiceGroupListColumn::TYPE_NOTE, 0);
        $this->column('Zwei', VoiceGroupListColumn::TYPE_NOTE, 4);

        $added = $this->persistence->addColumn($this->list, 'Drei', VoiceGroupListColumn::TYPE_CHECKBOX);

        self::assertSame(5, (int) $added->sort_order);
    }

    public function testArchivingAndRestoringSetsAndClearsTheTimestamp(): void
    {
        $this->persistence->archiveList($this->list);
        self::assertNotNull($this->list->fresh()->archived_at);

        $this->persistence->restoreList($this->list);
        self::assertNull($this->list->fresh()->archived_at);
    }
}
