<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\Role;
use App\Models\User;
use App\Util\PasswordHasher;
use Illuminate\Database\Capsule\Manager as Capsule;
use PHPUnit\Framework\TestCase;
use Tests\Unit\Bootstrap;

/**
 * Ein archiviertes Konto hält keine Rolle mehr über dem niedrigsten vergebenen
 * Level.
 *
 * Seit Lauf 60 setzt `UserEditPolicy::canDeactivate()` das beim Archivieren
 * durch: Wer eine erhöhte Rolle trägt, wird erst heruntergestuft und dann
 * stillgelegt. Für die davor archivierten Konten galt das nicht - ihr
 * Rollensatz blieb absichtlich unangetastet, sonst steckten sie für immer im
 * Archiv fest.
 *
 * Genau diese Altkonten sind die Lücke, die der Review-Lauf 73 aufgedeckt hat:
 * `ProjectPersistence::addProjectMember()` holt ein archiviertes Konto zurück
 * ins Aktive, und dieser Weg kennt die Rollenhierarchie bewusst nicht (siehe
 * ProjectMemberPolicy). Ein Altkonto mit Vorstands- oder Admin-Rolle konnte so
 * auch von einer Stimmvertretung wieder aktiv werden, die nur
 * `can_assign_own_voice_group_to_project` hält - archivierte Konten behalten
 * ihre Stimmgruppen und stehen in der Kandidatenliste. Der Weg über das Archiv
 * ist dagegen hierarchiegeprüft.
 *
 * Die Migration zieht die Altkonten auf denselben Stand, den der normale Weg
 * seit Lauf 60 erzeugt. Geprüft wird hier mit genau den Anweisungen, die sie
 * ausführt - dasselbe Vorgehen wie in RoleImpliedPermissionsFeatureTest.
 */
final class ArchivedElevatedRolesCleanupFeatureTest extends TestCase
{
    private const MIGRATION = \StripElevatedRolesFromArchivedUsers::class;

    private const MIGRATION_FILE = '/db/migrations/20261113120000_strip_elevated_roles_from_archived_users.php';

    protected function setUp(): void
    {
        parent::setUp();
        Bootstrap::setupTestDatabase();
        Capsule::connection()->beginTransaction();

        // Migrationen liegen außerhalb des Autoloaders; geprüft wird genau die
        // Klasse, die auch läuft - wie in RoleImpliedPermissionsFeatureTest.
        require_once dirname(__DIR__, 2) . self::MIGRATION_FILE;
    }

    protected function tearDown(): void
    {
        $connection = Capsule::connection();
        if ($connection->transactionLevel() > 0) {
            $connection->rollBack();
        }

        parent::tearDown();
    }

    private function makeUser(bool $active): User
    {
        return User::create([
            'email' => 'archived_roles_' . bin2hex(random_bytes(6)) . '@example.test',
            'password' => PasswordHasher::hash('secret'),
            'first_name' => 'Rita',
            'last_name' => 'Rückstufung',
            'is_active' => $active ? 1 : 0,
        ]);
    }

    private function makeRole(int $level): Role
    {
        return Role::create([
            'name' => 'Prüfrolle ' . $level . ' ' . bin2hex(random_bytes(4)),
            'hierarchy_level' => $level,
        ]);
    }

    private function minimalLevel(): int
    {
        return Role::minimalHierarchyLevel();
    }

    /**
     * @return list<int>
     */
    private function roleIdsOf(User $user): array
    {
        return Capsule::table('user_roles')
            ->where('user_id', (int) $user->id)
            ->orderBy('role_id')
            ->pluck('role_id')
            ->map(static fn ($id): int => (int) $id)
            ->all();
    }

    private function strip(): void
    {
        Capsule::connection()->statement(
            sprintf(self::MIGRATION::STRIP_SQL, $this->minimalLevel())
        );
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function accounts(): array
    {
        return array_map(
            static fn ($row): array => (array) $row,
            Capsule::select(sprintf(self::MIGRATION::ACCOUNTS_SQL, $this->minimalLevel()))
        );
    }

    public function testAnArchivedAccountLosesItsElevatedRoleAndKeepsTheBaseline(): void
    {
        $baseline = Role::query()->where('hierarchy_level', $this->minimalLevel())->orderBy('id')->firstOrFail();
        $elevated = $this->makeRole($this->minimalLevel() + 50);

        $user = $this->makeUser(false);
        $user->roles()->attach([(int) $baseline->id, (int) $elevated->id]);

        $this->strip();

        $this->assertSame([(int) $baseline->id], $this->roleIdsOf($user));
    }

    /**
     * Ein Konto, das nur die erhöhte Rolle trug, stünde nach dem Abziehen ohne
     * jede Rolle da - ein Zustand, den weder create() noch update() je erzeugen,
     * beide verlangen mindestens eine Rolle. Es bekommt deshalb die Rolle des
     * niedrigsten Levels, also denselben Stand wie ein seit Lauf 60 regulär
     * archiviertes Konto.
     */
    public function testAnArchivedAccountWithoutABaselineRoleGetsOne(): void
    {
        $baseline = Role::query()->where('hierarchy_level', $this->minimalLevel())->orderBy('id')->firstOrFail();
        $elevated = $this->makeRole($this->minimalLevel() + 50);

        $user = $this->makeUser(false);
        $user->roles()->attach((int) $elevated->id);

        $stranded = self::MIGRATION::strandedAccounts($this->accounts());
        $this->assertContains((int) $user->id, $stranded);

        $this->strip();
        $this->assertSame([], $this->roleIdsOf($user), 'Vor dem Nachsetzen hält es nichts mehr.');

        Capsule::connection()->statement(
            sprintf(self::MIGRATION::BASELINE_SQL, (int) $user->id, (int) $baseline->id)
        );

        $this->assertSame([(int) $baseline->id], $this->roleIdsOf($user));
    }

    public function testAnActiveAccountKeepsItsElevatedRole(): void
    {
        $elevated = $this->makeRole($this->minimalLevel() + 50);

        $user = $this->makeUser(true);
        $user->roles()->attach((int) $elevated->id);

        $this->strip();

        $this->assertSame([(int) $elevated->id], $this->roleIdsOf($user));
    }

    /**
     * Ein archiviertes Konto, das ohnehin nur die unterste Rolle trägt, wird
     * nicht angefasst - es steht weder in der Liste der betroffenen Konten noch
     * verliert es etwas.
     */
    public function testAnArchivedAccountOnTheLowestLevelStaysUntouched(): void
    {
        $baseline = Role::query()->where('hierarchy_level', $this->minimalLevel())->orderBy('id')->firstOrFail();

        $user = $this->makeUser(false);
        $user->roles()->attach((int) $baseline->id);

        $userIds = array_column($this->accounts(), 'user_id');
        $this->assertNotContains((int) $user->id, array_map('intval', $userIds));

        $this->strip();

        $this->assertSame([(int) $baseline->id], $this->roleIdsOf($user));
    }

    /**
     * Mehrere Rollen auf dem untersten Level sind erlaubt - welche davon die
     * Vorgabe ist, sagt dann aber niemand. Die Migration rät nicht, sondern
     * bricht ab; dieselbe Zurückhaltung wie in
     * UserController::defaultRoleIds(), das in diesem Fall ebenfalls nichts
     * vorauswählt.
     */
    public function testTheBaselineRoleIsOnlyChosenWhenItIsUnambiguous(): void
    {
        $this->assertSame(7, self::MIGRATION::baselineRoleIdFor([7]));
        $this->assertNull(self::MIGRATION::baselineRoleIdFor([7, 9]));
        $this->assertNull(self::MIGRATION::baselineRoleIdFor([]));
    }

    /**
     * Die Prüfung steht vor dem Löschen, nicht dahinter - danach wäre die
     * Zuordnung fort und der Lauf in phinxlog bereits verbucht.
     */
    public function testTheGuardRunsBeforeTheDelete(): void
    {
        $source = file_get_contents(dirname(__DIR__, 2) . self::MIGRATION_FILE);
        $this->assertIsString($source);

        $throw = strpos($source, 'throw new \RuntimeException');
        $delete = strpos($source, 'STRIP_SQL,');

        $this->assertIsInt($throw, 'Die Migration bricht nirgends ab.');
        $this->assertIsInt($delete, 'Die Migration löscht nirgends.');
        $this->assertLessThan($delete, $throw);
    }
}
