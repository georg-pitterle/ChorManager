<?php

declare(strict_types=1);

namespace Tests\Unit\Services;

use App\Logging\RequestContext;
use App\Models\Role;
use App\Models\User;
use App\Models\VoiceGroup;
use App\Services\NameFormatterService;
use App\Services\SessionAuthService;
use Illuminate\Database\Capsule\Manager as Capsule;
use PHPUnit\Framework\TestCase;
use Tests\Unit\Bootstrap;

/**
 * Was beim Anmelden in der Sitzung landet.
 *
 * Der Dienst schrieb jedes Recht einzeln aus: eine Variable, eine Abfrage, eine
 * Zuweisung - dreimal derselbe Handgriff je Recht. Diese Prüfung hält fest,
 * welche Werte dabei herauskommen, damit der Umbau auf eine Rechte-Liste
 * nachweislich nichts verschiebt. Sie ist deshalb bewusst vollständig und nennt
 * jeden Schlüssel beim Namen, statt über dieselbe Liste zu laufen wie der
 * Dienst - sonst bewiese sie nur, dass eine Liste sich selbst gleicht.
 */
final class SessionAuthServicePermissionMappingTest extends TestCase
{
    /**
     * Jedes Recht der Rollenverwaltung und der Sitzungsschlüssel, unter dem es
     * danach steht.
     */
    private const EXPECTED_SESSION_KEYS = [
        'can_manage_users',
        'can_manage_roles',
        'can_edit_users',
        'can_manage_attendance_all',
        'can_manage_events',
        'can_manage_project_members',
        'can_read_finances',
        'can_manage_finances',
        'can_manage_master_data',
        'can_manage_sponsoring',
        'can_create_own_sponsorships',
        'can_manage_song_library',
        'can_manage_newsletters',
        'can_manage_mail_queue',
        'can_manage_sheet_archive',
        'can_manage_budget',
        'can_manage_tasks',
        'can_manage_backups',
        'can_manage_own_voice_group',
        'can_assign_own_voice_group_to_project',
    ];

    protected function setUp(): void
    {
        parent::setUp();
        Bootstrap::setupTestDatabase();
        Capsule::connection()->beginTransaction();
        $_SESSION = [];
    }

    protected function tearDown(): void
    {
        $connection = Capsule::connection();
        if ($connection->transactionLevel() > 0) {
            $connection->rollBack();
        }

        $_SESSION = [];
        parent::tearDown();
    }

    /**
     * Eine Rolle ohne jedes Recht belegt trotzdem jeden Schlüssel - mit false.
     * Fehlte einer, läse jede Abfrage darauf "kein Recht", was zufällig richtig
     * wäre; bei einem Rechte-Wechsel in derselben Sitzung bliebe dagegen der
     * alte Wert stehen.
     */
    public function testEverySessionKeyIsSetEvenWithoutAnyPermission(): void
    {
        $this->authenticateWithRole([]);

        foreach (self::EXPECTED_SESSION_KEYS as $key) {
            $this->assertArrayHasKey($key, $_SESSION, 'Sitzungsschlüssel fehlt: ' . $key);
            $this->assertFalse($_SESSION[$key], 'Ohne Rolle mit Recht muss ' . $key . ' false sein.');
        }
    }

    public function testEveryPermissionOfTheRoleReachesItsSessionKey(): void
    {
        $this->authenticateWithRole(array_fill_keys(self::EXPECTED_SESSION_KEYS, 1));

        foreach (self::EXPECTED_SESSION_KEYS as $key) {
            $this->assertTrue($_SESSION[$key], 'Recht kommt nicht in der Sitzung an: ' . $key);
        }
    }

    /**
     * Ein einzelnes Recht darf kein anderes mitziehen - abgesehen von den beiden
     * Vollrechten unten, die ein kleineres ausdrücklich einschließen.
     */
    public function testASinglePermissionDoesNotLeakIntoTheOthers(): void
    {
        $this->authenticateWithRole(['can_manage_tasks' => 1]);

        $this->assertTrue($_SESSION['can_manage_tasks']);

        foreach (self::EXPECTED_SESSION_KEYS as $key) {
            if ($key === 'can_manage_tasks') {
                continue;
            }

            $this->assertFalse($_SESSION[$key], $key . ' darf von can_manage_tasks nicht mitgesetzt werden.');
        }
    }

    /**
     * Wer die Finanzen verwalten darf, darf sie auch lesen.
     */
    public function testManagingFinancesImpliesReadingThem(): void
    {
        $this->authenticateWithRole(['can_manage_finances' => 1]);

        $this->assertTrue($_SESSION['can_manage_finances']);
        $this->assertTrue($_SESSION['can_read_finances']);
    }

    /**
     * Und wer das Sponsoring verwaltet, darf auch eigene Patenschaften anlegen.
     */
    public function testManagingSponsoringImpliesCreatingOwnSponsorships(): void
    {
        $this->authenticateWithRole(['can_manage_sponsoring' => 1]);

        $this->assertTrue($_SESSION['can_manage_sponsoring']);
        $this->assertTrue($_SESSION['can_create_own_sponsorships']);
    }

    /**
     * Die Umkehrung gilt nicht: Das kleinere Recht öffnet das Vollrecht nicht.
     */
    public function testTheSmallerPermissionDoesNotOpenTheFullOne(): void
    {
        $this->authenticateWithRole(['can_read_finances' => 1, 'can_create_own_sponsorships' => 1]);

        $this->assertTrue($_SESSION['can_read_finances']);
        $this->assertFalse($_SESSION['can_manage_finances']);
        $this->assertTrue($_SESSION['can_create_own_sponsorships']);
        $this->assertFalse($_SESSION['can_manage_sponsoring']);
    }

    /**
     * Mehrere Rollen ergänzen einander: Jedes Recht, das irgendeine Rolle trägt,
     * gilt. Das Hierarchie-Level ist dagegen das höchste, nicht die Summe.
     */
    public function testSeveralRolesAddUpAndTheHighestLevelWins(): void
    {
        $user = $this->createUser();
        $user->roles()->attach($this->createRole(['can_manage_events' => 1], 20)->id);
        $user->roles()->attach($this->createRole(['can_manage_budget' => 1], 50)->id);
        $user->roles()->attach($this->createRole([], 35)->id);

        $this->authenticate($user);

        $this->assertTrue($_SESSION['can_manage_events']);
        $this->assertTrue($_SESSION['can_manage_budget']);
        $this->assertFalse($_SESSION['can_manage_users']);
        $this->assertSame(50, $_SESSION['role_level']);
    }

    /**
     * Ohne Rolle gibt es kein Recht und kein Level - und keine Fehlermeldung.
     */
    public function testAUserWithoutAnyRoleGetsNoPermissionAndLevelZero(): void
    {
        $user = $this->createUser();
        $this->authenticate($user);

        $this->assertSame(0, $_SESSION['role_level']);

        foreach (self::EXPECTED_SESSION_KEYS as $key) {
            $this->assertFalse($_SESSION[$key], $key . ' darf ohne Rolle nicht gesetzt sein.');
        }
    }

    public function testTheSessionCarriesIdentityVoiceGroupsAndEpoch(): void
    {
        $voiceGroup = VoiceGroup::create(['name' => 'Sopran ' . bin2hex(random_bytes(4))]);

        $user = $this->createUser();
        $user->voiceGroups()->attach($voiceGroup->id);

        $this->authenticate($user);

        $this->assertSame((int) $user->id, $_SESSION['user_id']);
        $this->assertSame('Alexa Meier', $_SESSION['user_name']);
        $this->assertSame([(int) $voiceGroup->id], array_map('intval', $_SESSION['voice_group_ids']));
        $this->assertIsInt($_SESSION['auth_epoch']);
    }

    /**
     * @param array<string, int> $permissions
     */
    private function authenticateWithRole(array $permissions): void
    {
        $user = $this->createUser();
        $user->roles()->attach($this->createRole($permissions)->id);

        $this->authenticate($user);
    }

    private function authenticate(User $user): void
    {
        $user->load('roles', 'voiceGroups');

        (new SessionAuthService(new NameFormatterService(), new RequestContext()))
            ->setAuthenticatedUser($user);
    }

    /**
     * @param array<string, int> $permissions
     */
    private function createRole(array $permissions, int $hierarchyLevel = 10): Role
    {
        return Role::create(array_merge([
            'name' => 'Rechte-Prüfung ' . bin2hex(random_bytes(4)),
            'hierarchy_level' => $hierarchyLevel,
        ], $permissions));
    }

    private function createUser(): User
    {
        return User::create([
            'first_name' => 'Alexa',
            'last_name' => 'Meier',
            'email' => 'rechte.' . bin2hex(random_bytes(6)) . '@example.test',
            'password' => password_hash('irrelevant', PASSWORD_DEFAULT),
            'is_active' => 1,
        ]);
    }
}
