<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\Attachment;
use App\Models\Sponsor;
use App\Models\Sponsorship;
use App\Models\User;
use App\Policies\SponsoringPolicy;
use App\Policies\TaskPolicy;
use App\Services\AttachmentAccessRegistry;
use Illuminate\Database\Capsule\Manager as Capsule;
use PHPUnit\Framework\TestCase;
use Tests\Unit\Bootstrap;

/**
 * Die Tabelle, die entscheidet, wer einen Anhang sehen darf.
 *
 * Sie ersetzt fünf verstreute Prüfungen. Zwei Eigenschaften sind ihr Zweck:
 * ein unbekannter `entity_type` wird abgelehnt statt durchgelassen, und ein
 * abgeschaltetes Modul sperrt seine Anhänge - vorher erledigte das die
 * Routen-Datei, in der die alten Routen innerhalb der Modul-Blöcke lagen.
 *
 * Sponsor-, Vereinbarungs- und Lied-Regeln brauchen echte Datensätze, sonst
 * prüft ein Test nur, dass eine leere Abfrage leer bleibt. Jeder Testfall
 * läuft deshalb in einer eigenen Transaktion, die `tearDown()` zurückrollt -
 * damit bleibt weder in der Datenbank noch in der Sitzung ein Rest stehen.
 */
final class AttachmentAccessRegistryFeatureTest extends TestCase
{
    private int $sponsorOwnerId = 0;

    private int $requestingUserId = 0;

    private int $memberUserId = 0;

    private Sponsor $sponsor;

    private Sponsorship $sponsorship;

    private int $projectId = 0;

    private int $songId = 0;

    private int $unassignedSongId = 0;

    protected function setUp(): void
    {
        parent::setUp();
        Bootstrap::setupTestDatabase();
        Bootstrap::getCapsule()?->connection()->beginTransaction();

        if (session_status() === PHP_SESSION_NONE) {
            @session_start();
        }

        $_SESSION = [];

        $suffix = bin2hex(random_bytes(4));

        $this->sponsorOwnerId = (int) User::create([
            'email' => 'registry_owner_' . $suffix . '@example.test',
            'password' => password_hash('secret', PASSWORD_BCRYPT),
            'first_name' => 'Sina',
            'last_name' => 'Sponsorbesitz',
            'is_active' => 1,
        ])->id;

        $this->requestingUserId = (int) User::create([
            'email' => 'registry_requester_' . $suffix . '@example.test',
            'password' => password_hash('secret', PASSWORD_BCRYPT),
            'first_name' => 'Rudi',
            'last_name' => 'Anfragend',
            'is_active' => 1,
        ])->id;

        $this->memberUserId = (int) User::create([
            'email' => 'registry_member_' . $suffix . '@example.test',
            'password' => password_hash('secret', PASSWORD_BCRYPT),
            'first_name' => 'Mira',
            'last_name' => 'Mitglied',
            'is_active' => 1,
        ])->id;

        $this->sponsor = Sponsor::create([
            'name' => 'Zugriffstest-Sponsor ' . $suffix,
            'created_by_user_id' => $this->sponsorOwnerId,
        ]);

        $this->sponsorship = Sponsorship::create([
            'sponsor_id' => $this->sponsor->id,
            'created_by_user_id' => $this->sponsorOwnerId,
        ]);

        $this->projectId = (int) Capsule::table('projects')->insertGetId([
            'name' => 'Zugriffstest-Projekt ' . $suffix,
        ]);

        $this->songId = (int) Capsule::table('songs')->insertGetId([
            'title' => 'Zugriffstest-Lied ' . $suffix,
        ]);

        // Kein project_song_assignments-Eintrag: pinnt die song_id-Bedingung
        // im Join von maySeeSong() gegen jedes Lied irgendeines Projekts.
        $this->unassignedSongId = (int) Capsule::table('songs')->insertGetId([
            'title' => 'Zugriffstest-Lied-ohne-Zuordnung ' . $suffix,
        ]);

        Capsule::table('project_song_assignments')->insert([
            'project_id' => $this->projectId,
            'song_id' => $this->songId,
        ]);

        Capsule::table('project_users')->insert([
            'project_id' => $this->projectId,
            'user_id' => $this->memberUserId,
        ]);
    }

    protected function tearDown(): void
    {
        $connection = Bootstrap::getCapsule()?->connection();
        if ($connection !== null && $connection->transactionLevel() > 0) {
            $connection->rollBack();
        }

        $_SESSION = [];
        parent::tearDown();
    }

    /**
     * @param array<string, bool> $modules
     */
    private function registry(array $modules = []): AttachmentAccessRegistry
    {
        $defaults = [
            'finance' => true,
            'sponsoring' => true,
            'tasks' => true,
        ];

        return new AttachmentAccessRegistry(
            new SponsoringPolicy(),
            new TaskPolicy(),
            array_merge($defaults, $modules)
        );
    }

    private function attachment(string $entityType, int $entityId = 1): Attachment
    {
        $attachment = new Attachment();
        $attachment->entity_type = $entityType;
        $attachment->entity_id = $entityId;

        return $attachment;
    }

    public function testUnknownEntityTypeIsRejected(): void
    {
        $_SESSION['can_manage_finances'] = true;
        $_SESSION['can_manage_tasks'] = true;
        $_SESSION['can_manage_song_library'] = true;

        $this->assertFalse($this->registry()->mayAccess($this->attachment('newsletter'), 1));
        $this->assertFalse($this->registry()->mayAccess($this->attachment(''), 1));
    }

    /**
     * can_manage_song_library entscheidet nur über Lieder. Stünde die
     * Abfrage ganz oben in mayAccess() statt in maySeeSong(), gäbe sie auch
     * Finanzen, Aufgaben und Sponsoring frei.
     */
    public function testSongLibraryPermissionDoesNotLeakIntoOtherEntityTypes(): void
    {
        $_SESSION = ['can_manage_song_library' => true];

        $registry = $this->registry();

        $this->assertFalse($registry->mayAccess($this->attachment('finance'), 1));
        $this->assertFalse($registry->mayAccess($this->attachment('task'), 1));
        $this->assertFalse($registry->mayAccess($this->attachment('sponsor', (int) $this->sponsor->id), 1));
        $this->assertFalse($registry->mayAccess($this->attachment('sponsorship', (int) $this->sponsorship->id), 1));
    }

    public function testFinanceNeedsReadOrManagePermission(): void
    {
        $_SESSION['can_read_finances'] = true;
        $this->assertTrue($this->registry()->mayAccess($this->attachment('finance'), 1));

        $_SESSION = ['can_manage_finances' => true];
        $this->assertTrue($this->registry()->mayAccess($this->attachment('finance'), 1));

        $_SESSION = [];
        $this->assertFalse($this->registry()->mayAccess($this->attachment('finance'), 1));
    }

    public function testFinanceIsBlockedWhenModuleIsOff(): void
    {
        $_SESSION['can_manage_finances'] = true;

        $registry = $this->registry(['finance' => false]);

        $this->assertFalse($registry->mayAccess($this->attachment('finance'), 1));
    }

    public function testTaskNeedsTaskManagementAndModule(): void
    {
        $_SESSION['can_manage_tasks'] = true;
        $this->assertTrue($this->registry()->mayAccess($this->attachment('task'), 1));

        $this->assertFalse($this->registry(['tasks' => false])->mayAccess($this->attachment('task'), 1));

        $_SESSION = [];
        $this->assertFalse($this->registry()->mayAccess($this->attachment('task'), 1));
    }

    /**
     * Die Kennungen kommen aus den Fixtures, nicht aus einer erfundenen 1.
     * Mit einer nicht existierenden Kennung lieferte die Regel schon wegen des
     * fehlenden Datensatzes false - der Test bliebe grün, auch wenn jemand die
     * Modulprüfung aus dem Zweig entfernte.
     */
    public function testSponsorAndSponsorshipAreBlockedWhenModuleIsOff(): void
    {
        $_SESSION['can_manage_sponsoring'] = true;

        $registry = $this->registry(['sponsoring' => false]);

        $this->assertFalse(
            $registry->mayAccess($this->attachment('sponsor', (int) $this->sponsor->id), 1)
        );
        $this->assertFalse(
            $registry->mayAccess($this->attachment('sponsorship', (int) $this->sponsorship->id), 1)
        );
    }

    public function testSponsorAndSponsorshipAreGrantedWithFullManagePermission(): void
    {
        $_SESSION['can_manage_sponsoring'] = true;

        $registry = $this->registry();

        $this->assertTrue($registry->mayAccess($this->attachment('sponsor', (int) $this->sponsor->id), 1));
        $this->assertTrue($registry->mayAccess($this->attachment('sponsorship', (int) $this->sponsorship->id), 1));
    }

    /**
     * can_create_own_sponsorships gibt nur die eigenen Einträge frei. Der
     * Sponsor und die Vereinbarung im Fixture gehören sponsorOwnerId, nicht
     * der anfragenden Person - das muss abgelehnt werden.
     */
    public function testSponsorAndSponsorshipAreRejectedForForeignOwnershipWithCreateOwnPermission(): void
    {
        $_SESSION['can_create_own_sponsorships'] = true;
        $_SESSION['user_id'] = $this->requestingUserId;

        $registry = $this->registry();

        $this->assertFalse(
            $registry->mayAccess($this->attachment('sponsor', (int) $this->sponsor->id), $this->requestingUserId)
        );
        $this->assertFalse($registry->mayAccess(
            $this->attachment('sponsorship', (int) $this->sponsorship->id),
            $this->requestingUserId
        ));
    }

    /**
     * Die Gegenprobe zum Test darüber, und die wichtigere Hälfte: wer eigene
     * Einträge anlegen darf, muss an die Anhänge der **eigenen** herankommen.
     *
     * Ohne diese Zusicherung dürfte `maySeeSponsor()` und `maySeeSponsorship()`
     * zu konstantem `false` verkümmern und die Suite bliebe grün - eine
     * mitwirkende Person käme dann an ihren eigenen Vertrag nicht mehr heran.
     */
    public function testSponsorAndSponsorshipAreGrantedForOwnEntriesWithCreateOwnPermission(): void
    {
        $_SESSION['can_create_own_sponsorships'] = true;
        $_SESSION['user_id'] = $this->sponsorOwnerId;

        $registry = $this->registry();

        $this->assertTrue(
            $registry->mayAccess($this->attachment('sponsor', (int) $this->sponsor->id), $this->sponsorOwnerId)
        );
        $this->assertTrue($registry->mayAccess(
            $this->attachment('sponsorship', (int) $this->sponsorship->id),
            $this->sponsorOwnerId
        ));
    }

    public function testSongLibraryManagerMayAccessWithoutProjectMembership(): void
    {
        $_SESSION['can_manage_song_library'] = true;

        // entity_id zeigt auf ein Lied, das es nicht geben muss: das Recht
        // entscheidet, nicht die Zuordnung zu einem Projekt.
        $this->assertTrue($this->registry()->mayAccess($this->attachment('song', 999999), 1));
    }

    public function testSongWithoutPermissionAndWithoutMembershipIsRejected(): void
    {
        $this->assertFalse($this->registry()->mayAccess($this->attachment('song', 999999), 1));
    }

    /**
     * Der Mitgliedschaftsweg selbst, ohne can_manage_song_library: wer im
     * Projekt Mitglied ist, das dieses Lied singt, darf den Anhang sehen -
     * wer es nicht ist, nicht. Das grenzt den `project_users.user_id`-Filter
     * der Verknüpfung konkret ein, statt nur eine leere Abfrage zu prüfen.
     */
    public function testSongProjectMemberMayAccessWithoutSongLibraryPermission(): void
    {
        $registry = $this->registry();
        $songAttachment = $this->attachment('song', $this->songId);

        $this->assertTrue($registry->mayAccess($songAttachment, $this->memberUserId));
        $this->assertFalse($registry->mayAccess($songAttachment, $this->requestingUserId));
    }

    /**
     * Die Mitgliedschaft gibt nur das zugeordnete Lied frei, nicht jedes
     * Lied. Ohne die song_id-Bedingung im Join von maySeeSong() würde der
     * Mitgliedschaftsweg jedes Lied irgendeines Projekts freigeben, dem die
     * Person angehört - genau die Lücke, die die Registry verhindern soll.
     */
    public function testSongProjectMembershipDoesNotGrantAccessToUnrelatedSong(): void
    {
        $registry = $this->registry();

        $this->assertFalse(
            $registry->mayAccess($this->attachment('song', $this->unassignedSongId), $this->memberUserId)
        );
    }

    /**
     * song ist bewusst keinem Modul zugeordnet - anders als finance, task,
     * sponsor und sponsorship. Diese Prüfung dokumentiert die Entscheidung:
     * ein Projektmitglied darf das Notenblatt sehen, selbst wenn alle drei
     * Module abgeschaltet sind.
     */
    public function testSongAccessIsNotGatedByAnyModule(): void
    {
        $registry = $this->registry(['finance' => false, 'sponsoring' => false, 'tasks' => false]);

        $this->assertTrue(
            $registry->mayAccess($this->attachment('song', $this->songId), $this->memberUserId)
        );
    }

    /**
     * Sponsor::find() und Sponsorship::find() liefern für eine nicht
     * existierende Kennung null. Dieser Zweig lief bisher nie, weil der
     * einzige Test mit einer erfundenen Kennung schon am Modul-Gate
     * scheiterte, bevor find() überhaupt aufgerufen wurde.
     */
    public function testSponsorAndSponsorshipAreRejectedForNonexistentRecord(): void
    {
        $_SESSION['can_manage_sponsoring'] = true;

        $registry = $this->registry();

        $this->assertFalse($registry->mayAccess($this->attachment('sponsor', 999999), 1));
        $this->assertFalse($registry->mayAccess($this->attachment('sponsorship', 999999), 1));
    }
}
