<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Controllers\UserController;
use App\Models\Role;
use App\Models\User;
use App\Persistence\ProjectPersistence;
use App\Persistence\UserPersistence;
use App\Policies\UserEditPolicy;
use App\Queries\UserQuery;
use App\Services\MailQueueService;
use App\Services\NameFormatterService;
use App\Util\PasswordHasher;
use Illuminate\Database\Capsule\Manager as Capsule;
use Monolog\Logger;
use PHPUnit\Framework\TestCase;
use Psr\Http\Message\ResponseInterface;
use Slim\Views\Twig;
use Tests\Unit\Bootstrap;

/**
 * Archiviert wird nur, wer keine Rolle mehr über dem niedrigsten vergebenen
 * Level hält. Die Rollen kommen also zuerst herunter, dann das Konto ins Archiv.
 *
 * Der Grund steht an der Wiederherstellung: ein archiviertes Konto lässt sich
 * nicht nur über die Mitgliederverwaltung zurückholen, sondern auch dadurch,
 * dass jemand es einem Projekt zuordnet (ProjectPersistence::addProjectMember()).
 * Dieser Weg kennt die Rollenhierarchie bewusst nicht. Trägt das Konto beim
 * Archivieren nichts Erhöhtes mehr, kann er auch nichts Erhöhtes zurückholen.
 */
final class UserArchiveRequiresMinimalRoleFeatureTest extends TestCase
{
    use TestHttpHelpers;

    private Role $baseRole;
    private Role $elevatedRole;
    private User $target;

    protected function setUp(): void
    {
        parent::setUp();
        Bootstrap::setupTestDatabase();
        Capsule::connection()->beginTransaction();

        $suffix = bin2hex(random_bytes(4));

        // Die niedrigste Rolle im Bestand liegt hier bewusst nicht auf 0: die
        // Regel richtet sich nach dem tatsächlichen Minimum, nicht nach der Zahl.
        Capsule::table('roles')->update(['hierarchy_level' => Capsule::raw('hierarchy_level + 100')]);

        $this->baseRole = Role::create(['name' => 'Singend ' . $suffix, 'hierarchy_level' => 5]);
        $this->elevatedRole = Role::create(['name' => 'Leitung ' . $suffix, 'hierarchy_level' => 60]);

        $this->target = User::create([
            'first_name' => 'Zu',
            'last_name' => 'Archivieren',
            'email' => 'archive.target.' . $suffix . '@example.test',
            'password' => PasswordHasher::hash('irrelevant'),
            'is_active' => 1,
        ]);
        $this->target->roles()->attach([$this->baseRole->id, $this->elevatedRole->id]);

        $_SESSION = [
            'user_id' => 999101,
            'can_manage_users' => true,
            'can_edit_users' => true,
            'role_level' => 900,
            'voice_group_ids' => [],
        ];
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

    /** @var list<array{0: string, 1: array<string, mixed>}> */
    private array $renderCalls = [];

    private function controller(): UserController
    {
        $logger = new Logger('test');

        $twig = $this->createStub(Twig::class);
        $twig->method('render')->willReturnCallback(
            function (ResponseInterface $response, string $template, array $data = []): ResponseInterface {
                $this->renderCalls[] = [$template, $data];
                return $response;
            }
        );

        return new UserController(
            $twig,
            new UserQuery(new NameFormatterService()),
            new UserPersistence($logger),
            new ProjectPersistence(),
            $this->createStub(MailQueueService::class),
            $logger,
            new UserEditPolicy()
        );
    }

    private function deactivate(): void
    {
        $this->controller()->deactivate(
            $this->makeRequest('POST', '/users/deactivate/' . $this->target->id),
            $this->makeResponse(),
            ['id' => (string) $this->target->id]
        );
    }

    public function testAMemberWithAnElevatedRoleIsNotArchived(): void
    {
        $this->deactivate();

        $this->assertSame(1, (int) $this->target->fresh()->is_active);
        $this->assertStringContainsString('Rolle', (string) ($_SESSION['error'] ?? ''));
    }

    public function testTheSameMemberIsArchivedOnceOnlyTheLowestRoleRemains(): void
    {
        $this->target->roles()->detach($this->elevatedRole->id);

        $this->deactivate();

        $this->assertSame(0, (int) $this->target->fresh()->is_active);
        $this->assertNotEmpty($_SESSION['success'] ?? '');
    }

    /**
     * Ganz ohne Rolle ist nichts vorhanden, was überragen könnte.
     */
    public function testAMemberWithoutAnyRoleIsArchived(): void
    {
        $this->target->roles()->detach();

        $this->deactivate();

        $this->assertSame(0, (int) $this->target->fresh()->is_active);
    }

    /**
     * Die Sammelaktion folgt derselben Regel; das erhöhte Mitglied zählt dort
     * als fehlgeschlagen und bleibt aktiv.
     */
    public function testTheBulkActionFollowsTheSameRule(): void
    {
        $this->controller()->bulkDeactivate(
            $this->makeRequest('POST', '/users/bulk-deactivate', [
                'user_ids' => [(string) $this->target->id],
            ]),
            $this->makeResponse()
        );

        $this->assertSame(1, (int) $this->target->fresh()->is_active);
        $this->assertStringContainsString('1 fehlgeschlagen', (string) ($_SESSION['success'] ?? ''));
    }

    /**
     * Bestand: ein vor dieser Regel archiviertes Mitglied trägt noch seine alten
     * Rollen und muss zurückholbar bleiben.
     */
    public function testAnAlreadyArchivedMemberWithAnElevatedRoleCanStillBeRestored(): void
    {
        $this->target->is_active = 0;
        $this->target->save();

        $this->controller()->restore(
            $this->makeRequest('POST', '/users/restore/' . $this->target->id),
            $this->makeResponse(),
            ['id' => (string) $this->target->id]
        );

        $this->assertSame(1, (int) $this->target->fresh()->is_active);
    }

    /**
     * Die Mitgliederliste bietet den Archivieren-Knopf nur dort an, wo der Klick
     * auch durchgeht - sonst stünde dort ein Knopf, der zwangsläufig in einer
     * Meldung endet.
     */
    public function testTheListOnlyOffersArchivingWhereItWouldSucceed(): void
    {
        $lowOnly = User::create([
            'first_name' => 'Nur',
            'last_name' => 'Singend',
            'email' => 'low.only.' . bin2hex(random_bytes(4)) . '@example.test',
            'password' => PasswordHasher::hash('irrelevant'),
            'is_active' => 1,
        ]);
        $lowOnly->roles()->attach($this->baseRole->id);

        $this->controller()->index(
            $this->makeRequest('GET', '/users'),
            $this->makeResponse()
        );

        $this->assertNotSame([], $this->renderCalls);
        [$template, $data] = $this->renderCalls[0];

        $this->assertSame('users/manage.twig', $template);
        $this->assertArrayHasKey('can_archive_member', $data);
        $this->assertArrayNotHasKey((int) $this->target->id, $data['can_archive_member']);
        $this->assertArrayHasKey((int) $lowOnly->id, $data['can_archive_member']);
    }
}
