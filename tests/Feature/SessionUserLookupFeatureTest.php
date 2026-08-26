<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Queries\UserQuery;
use App\Services\NameFormatterService;
use Illuminate\Database\Capsule\Manager as Capsule;
use App\Models\Role;
use App\Models\SubVoice;
use App\Models\User;
use App\Models\VoiceGroup;
use PHPUnit\Framework\TestCase;
use Tests\Unit\Bootstrap;

/**
 * Der Sitzungs-Lookup läuft bei jedem geschützten Request. Er darf deshalb nur die
 * Relationen laden, die der Rechte-Schnappschuss auswertet, und keinesfalls den
 * Passwort-Hash in eine Abfrage ziehen, die auf jedem Seitenaufruf stattfindet.
 * Die Detailmasken brauchen weiterhin den vollständigen Lookup.
 */
class SessionUserLookupFeatureTest extends TestCase
{
    private const SESSION_RELATIONS = ['roles', 'voiceGroups'];
    private const DETAIL_RELATIONS = ['subVoices', 'mailAccount'];

    private int $userId = 0;
    private string $roleName = '';
    private string $email = '';

    protected function setUp(): void
    {
        parent::setUp();
        Bootstrap::setupTestDatabase();
        Bootstrap::getCapsule()?->connection()->beginTransaction();

        $sopran = VoiceGroup::where('name', 'Sopran')->firstOrFail();
        $sopran1 = SubVoice::where('voice_group_id', $sopran->id)->where('name', 'Sopran 1')->firstOrFail();

        $this->email = 'sessionlookup_' . bin2hex(random_bytes(6)) . '@example.test';
        $user = User::create([
            'email' => $this->email,
            // Der Hash darf die Query-Schicht nicht verlassen - genau das prüft diese Klasse.
            'password' => '$2y$12$souldneverleavethequerylayer',
            'first_name' => 'Anna',
            'last_name' => 'Alt',
            'is_active' => 1,
        ]);
        $this->userId = (int) $user->id;

        $this->roleName = 'Mitglied ' . bin2hex(random_bytes(4));
        $role = Role::create([
            'name' => $this->roleName,
            'hierarchy_level' => 0,
        ]);
        Capsule::table('user_roles')->insert(['user_id' => $this->userId, 'role_id' => $role->id]);
        Capsule::table('user_voice_groups')->insert([
            'user_id' => $this->userId,
            'voice_group_id' => $sopran->id,
            'sub_voice_id' => $sopran1->id,
        ]);
        // Das frühere Mini-Schema kannte hier nur zwei Spalten. Die echte Tabelle verlangt die
        // Postfach-Zugangsdaten, also werden sie mit angelegt.
        Capsule::table('user_mail_accounts')->insert([
            'user_id' => $this->userId,
            'imap_host' => 'imap.example.test',
            'imap_port' => 993,
            'imap_encryption' => 'ssl',
            'imap_username' => 'anna',
            'imap_password_enc' => 'verschluesselt',
        ]);
    }

    protected function tearDown(): void
    {
        $connection = Bootstrap::getCapsule()?->connection();
        if ($connection !== null && $connection->transactionLevel() > 0) {
            $connection->rollBack();
        }

        parent::tearDown();
    }

    private function query(): UserQuery
    {
        return new UserQuery(new NameFormatterService());
    }

    public function testSitzungsLookupLaedtDenPasswortHashNicht(): void
    {
        $user = $this->query()->findForSession($this->userId);

        $this->assertNotNull($user);
        $this->assertArrayNotHasKey(
            'password',
            $user->getAttributes(),
            'Der Sitzungs-Lookup läuft bei jedem Request und darf den Hash nicht mitladen.'
        );
    }

    public function testSitzungsLookupLiefertAlleFuerDenRechteSchnappschussNoetigenDaten(): void
    {
        $user = $this->query()->findForSession($this->userId);

        $this->assertNotNull($user);

        foreach (['id', 'first_name', 'last_name', 'is_active'] as $column) {
            $this->assertArrayHasKey($column, $user->getAttributes(), 'Spalte ' . $column . ' fehlt.');
        }

        foreach (self::SESSION_RELATIONS as $relation) {
            $this->assertTrue(
                $user->relationLoaded($relation),
                'Der Sitzungsaufbau liest ' . $relation . ' und braucht die Relation eager geladen.'
            );
        }

        $this->assertSame([1], $user->voiceGroups->pluck('id')->all());
        $this->assertSame($this->roleName, $user->roles->first()->name);
    }

    public function testSitzungsLookupLaedtKeineDetailRelationen(): void
    {
        $user = $this->query()->findForSession($this->userId);

        $this->assertNotNull($user);

        foreach (self::DETAIL_RELATIONS as $relation) {
            $this->assertFalse(
                $user->relationLoaded($relation),
                'Relation ' . $relation . ' kostet eine Abfrage pro Request und wird dort nicht gelesen.'
            );
        }

        $this->assertFalse(
            $user->voiceGroups->first()->relationLoaded('subVoices'),
            'Die Teilstimmen der Stimmgruppe werden im Sitzungsaufbau nicht gelesen.'
        );
    }

    public function testLoginLookupBehaeltDenHashUndLaedtNurDieSitzungsRelationen(): void
    {
        $user = $this->query()->findByEmail($this->email);

        $this->assertNotNull($user);
        $this->assertArrayHasKey(
            'password',
            $user->getAttributes(),
            'password_verify() braucht den Hash aus dem Login-Lookup.'
        );

        foreach (self::SESSION_RELATIONS as $relation) {
            $this->assertTrue($user->relationLoaded($relation));
        }

        foreach (self::DETAIL_RELATIONS as $relation) {
            $this->assertFalse(
                $user->relationLoaded($relation),
                'Der Login wertet ' . $relation . ' nicht aus.'
            );
        }
    }

    public function testDetailLookupLaedtWeiterhinTeilstimmenUndPostfach(): void
    {
        $user = $this->query()->findById($this->userId);

        $this->assertNotNull($user);

        foreach (self::DETAIL_RELATIONS as $relation) {
            $this->assertTrue(
                $user->relationLoaded($relation),
                'Die Detailmasken zeigen ' . $relation . ' und brauchen die Relation weiterhin.'
            );
        }

        $this->assertSame('Sopran 1', $user->subVoices->first()->name);
    }
}
