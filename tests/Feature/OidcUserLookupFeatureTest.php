<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\Role;
use App\Models\SubVoice;
use App\Models\User;
use App\Models\VoiceGroup;
use App\Queries\UserQuery;
use App\Services\NameFormatterService;
use App\Services\Oidc\OidcClaimsBuilder;
use App\Util\PasswordHasher;
use Illuminate\Database\Capsule\Manager as Capsule;
use PHPUnit\Framework\TestCase;
use Tests\Unit\Bootstrap;

/**
 * Der Mitglieder-Lookup hinter /oidc/authorize, /oidc/token und /oidc/userinfo.
 *
 * Die drei Zugänge brauchen genau zwei Dinge: die Spalten, aus denen
 * OidcClaimsBuilder die Ansprüche baut, und die Rollen für `groups`. Mit dem
 * Detail-Lookup gelesen kamen zusätzlich Stimmgruppen, Teilstimmen und das
 * Postfach mit - vier Abfragen, die niemand ausliest, und mit ihnen das
 * verschlüsselte IMAP-Passwort in genau den Zugang, der Daten an eine fremde
 * Anwendung ausliefert. Dieselbe Grenze wie bei findForSession().
 *
 * Die Spaltenauswahl ist dabei die Falle: `external_uid` steht nicht in
 * User::LIST_COLUMNS. Fehlte sie, wiese sich jedes Mitglied stillschweigend
 * mit der abgeleiteten Form `cm-<id>` aus, und die angeschlossene Anwendung
 * fände die bestehenden Konten nicht mehr wieder. Der Vergleich gegen
 * findById() hält das fest.
 */
final class OidcUserLookupFeatureTest extends TestCase
{
    private const FULL_SCOPE = 'openid profile email groups';

    /** Relationen, die der OIDC-Zugang nicht liest. */
    private const UNUSED_RELATIONS = ['voiceGroups', 'subVoices', 'mailAccount'];

    private int $userId = 0;

    protected function setUp(): void
    {
        parent::setUp();
        Bootstrap::setupTestDatabase();
        Capsule::connection()->beginTransaction();

        $user = User::create([
            'email' => 'oidc-lookup-' . bin2hex(random_bytes(6)) . '@example.test',
            'password' => PasswordHasher::hash('irrelevant'),
            'first_name' => 'Jonas',
            'last_name' => 'Öllinger',
            'is_active' => 1,
            'external_uid' => 'nc-' . bin2hex(random_bytes(4)),
        ]);
        $this->userId = (int) $user->id;

        $role = Role::create([
            'name' => 'Vorstand ' . bin2hex(random_bytes(4)),
            'hierarchy_level' => 50,
            'external_group' => 'vorstand',
        ]);
        Capsule::table('user_roles')->insert(['user_id' => $this->userId, 'role_id' => $role->id]);

        // Stimmgruppe, Teilstimme und Postfach gibt es nur, damit der
        // Detail-Lookup hier tatsächlich etwas zu laden hätte.
        $sopran = VoiceGroup::where('name', 'Sopran')->firstOrFail();
        $sopran1 = SubVoice::where('voice_group_id', $sopran->id)->where('name', 'Sopran 1')->firstOrFail();
        Capsule::table('user_voice_groups')->insert([
            'user_id' => $this->userId,
            'voice_group_id' => $sopran->id,
            'sub_voice_id' => $sopran1->id,
        ]);
        Capsule::table('user_mail_accounts')->insert([
            'user_id' => $this->userId,
            'imap_host' => 'imap.example.test',
            'imap_port' => 993,
            'imap_encryption' => 'ssl',
            'imap_username' => 'jonas',
            'imap_password_enc' => 'verschlüsselt',
        ]);
    }

    protected function tearDown(): void
    {
        $connection = Capsule::connection();
        if ($connection->transactionLevel() > 0) {
            $connection->rollBack();
        }

        parent::tearDown();
    }

    private function query(): UserQuery
    {
        return new UserQuery(new NameFormatterService());
    }

    public function testTheOidcLookupLeavesThePasswordHashAndTheMailboxInTheDatabase(): void
    {
        $user = $this->query()->findForOidc($this->userId);

        $this->assertNotNull($user);
        $this->assertArrayNotHasKey(
            'password',
            $user->getAttributes(),
            'Der OIDC-Zugang gleicht kein Passwort ab und braucht den Hash nicht.'
        );

        foreach (self::UNUSED_RELATIONS as $relation) {
            $this->assertFalse(
                $user->relationLoaded($relation),
                'Relation ' . $relation . ' wird im OIDC-Zugang nicht gelesen.'
            );
        }
    }

    public function testTheOidcLookupCostsTwoQueries(): void
    {
        $connection = Capsule::connection();
        $connection->flushQueryLog();
        $connection->enableQueryLog();

        $this->query()->findForOidc($this->userId);

        $queries = $connection->getQueryLog();
        $connection->disableQueryLog();

        $this->assertCount(
            2,
            $queries,
            'Das Mitglied und seine Rollen - mehr liest der OIDC-Zugang nicht.'
        );
    }

    public function testTheNarrowedColumnSetStillYieldsTheSameClaims(): void
    {
        $builder = new OidcClaimsBuilder();
        $query = $this->query();

        $narrow = $query->findForOidc($this->userId);
        $full = $query->findById($this->userId);

        $this->assertNotNull($narrow);
        $this->assertNotNull($full);
        $this->assertSame(
            $builder->build($full, self::FULL_SCOPE),
            $builder->build($narrow, self::FULL_SCOPE),
            'Die enge Spaltenauswahl darf keinen Anspruch verändern - allen voran `sub`.'
        );
    }

    public function testADeactivatedMemberNeverReachesTheConnectedApplication(): void
    {
        User::where('id', $this->userId)->update(['is_active' => 0]);

        $this->assertNull(
            $this->query()->findForOidc($this->userId),
            'Ein gesperrtes Mitglied darf keine Anmeldung in einer fremden Anwendung mehr bekommen.'
        );
    }
}
