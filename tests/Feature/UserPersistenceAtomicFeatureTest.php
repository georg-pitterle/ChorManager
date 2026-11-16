<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\Project;
use App\Models\Role;
use App\Models\User;
use App\Persistence\ProjectPersistence;
use App\Persistence\UserPersistence;
use App\Util\PasswordHasher;
use Illuminate\Database\Capsule\Manager as Capsule;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use RuntimeException;
use Tests\Unit\Bootstrap;

/**
 * Ein Mitglied wird samt Rollen, Stimmgruppen und Projekten ganz oder gar nicht
 * gespeichert.
 *
 * UserController::create() und ::update() schrieben das Konto und danach Rollen,
 * Stimmgruppen und Projekte in vier getrennten Schritten. Scheiterte die
 * Projektzuordnung, stand ein neues Mitglied ohne Zuordnung in der Datenbank,
 * während der Aufrufer "Fehler beim Anlegen" meldete - und ein bearbeitetes
 * trug den neuen Namen, aber die alten Rollen oder umgekehrt.
 */
final class UserPersistenceAtomicFeatureTest extends TestCase
{
    private Role $roleA;
    private Role $roleB;
    private string $suffix;

    protected function setUp(): void
    {
        parent::setUp();
        Bootstrap::setupTestDatabase();
        Capsule::connection()->beginTransaction();

        $this->suffix = bin2hex(random_bytes(4));
        $this->roleA = Role::create(['name' => 'Rolle A ' . $this->suffix, 'hierarchy_level' => 10]);
        $this->roleB = Role::create(['name' => 'Rolle B ' . $this->suffix, 'hierarchy_level' => 10]);
    }

    protected function tearDown(): void
    {
        $connection = Capsule::connection();
        if ($connection->transactionLevel() > 0) {
            $connection->rollBack();
        }

        parent::tearDown();
    }

    /** Eine Projektzuordnung, die immer scheitert. */
    private function failingProjects(): ProjectPersistence
    {
        return new class extends ProjectPersistence {
            public function setUserProjects(int $userId, array $projectIds): void
            {
                throw new RuntimeException('Projektzuordnung nicht schreibbar');
            }
        };
    }

    private function newUser(string $firstName): User
    {
        $user = new User();
        $user->first_name = $firstName;
        $user->last_name = 'Atomar';
        $user->email = 'atomar.' . $this->suffix . '@example.test';
        $user->password = PasswordHasher::hash('Test-Passwort-1');
        $user->is_active = 1;

        return $user;
    }

    public function testANewMemberIsNotLeftBehindWhenTheProjectAssignmentFails(): void
    {
        $persistence = new UserPersistence(new NullLogger(), null, $this->failingProjects());
        $user = $this->newUser('Neu');

        try {
            $persistence->saveWithAssignments($user, [(int) $this->roleA->id], [], [1]);
            self::fail('Die Projektzuordnung hätte scheitern müssen.');
        } catch (RuntimeException) {
            // erwartet
        }

        self::assertFalse(
            User::where('email', 'atomar.' . $this->suffix . '@example.test')->exists(),
            'Das Mitglied darf ohne seine Zuordnungen nicht in der Datenbank stehen.'
        );
    }

    public function testAnExistingMemberKeepsNameAndRolesWhenTheProjectAssignmentFails(): void
    {
        $user = $this->newUser('Alt');
        $user->save();
        $user->roles()->sync([(int) $this->roleA->id]);

        $persistence = new UserPersistence(new NullLogger(), null, $this->failingProjects());
        $user->first_name = 'Neu';

        try {
            $persistence->saveWithAssignments($user, [(int) $this->roleB->id], [], [1]);
            self::fail('Die Projektzuordnung hätte scheitern müssen.');
        } catch (RuntimeException) {
            // erwartet
        }

        $reloaded = User::findOrFail($user->id);
        self::assertSame('Alt', $reloaded->first_name);
        self::assertSame(
            [(int) $this->roleA->id],
            $reloaded->roles()->pluck('roles.id')->map(static fn ($id): int => (int) $id)->all()
        );
    }

    public function testProjectsStayUntouchedWhenNoProjectListIsGiven(): void
    {
        $persistence = new UserPersistence(new NullLogger(), null, $this->failingProjects());
        $user = $this->newUser('Ohne Projekte');

        $persistence->saveWithAssignments($user, [(int) $this->roleA->id], [], null);

        self::assertTrue(User::where('email', 'atomar.' . $this->suffix . '@example.test')->exists());
    }

    public function testTheProjectsAreWrittenWhenAListIsGiven(): void
    {
        $project = Project::create([
            'name' => 'Atomar ' . $this->suffix,
            'start_date' => '2026-09-01',
            'end_date' => '2099-06-30',
        ]);
        $persistence = new UserPersistence(new NullLogger());
        $user = $this->newUser('Mit Projekt');

        $persistence->saveWithAssignments($user, [(int) $this->roleA->id], [], [(int) $project->id]);

        $reloaded = User::findOrFail($user->id);
        self::assertSame(
            [(int) $project->id],
            $reloaded->projects()->pluck('projects.id')->map(static fn ($id): int => (int) $id)->all()
        );
    }
}
