<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\Project;
use App\Models\SubVoice;
use App\Models\User;
use App\Models\VoiceGroup;
use App\Queries\UserQuery;
use App\Services\NameFormatterService;
use App\Util\PasswordHasher;
use PHPUnit\Framework\TestCase;
use Tests\Unit\Bootstrap;

/**
 * Die Mitgliederliste lädt genau die Beziehungen, die sie auch anzeigt.
 *
 * Die Liste (`/users`) löst den Namen einer Teilstimme über die separat geladene
 * Gesamtliste `sub_voices` auf und liest dafür nur die Kennung aus dem Pivot der
 * Stimmgruppe. Die Ketten `voiceGroups.subVoices` und `subVoices.voiceGroup`
 * wurden trotzdem mitgeladen - zwei zusätzliche Abfragen je Seitenaufruf, von
 * denen `voiceGroups.subVoices` sämtliche Teilstimmen sämtlicher Stimmgruppen
 * holt. Diese Klasse hält beides auseinander: was die Liste braucht, bleibt
 * geladen; was sie nie liest, kommt nicht zurück.
 */
class UserListEagerLoadFeatureTest extends TestCase
{
    private int $userId = 0;
    private int $subVoiceId = 0;

    protected function setUp(): void
    {
        parent::setUp();
        Bootstrap::setupTestDatabase();
        Bootstrap::getCapsule()?->connection()->beginTransaction();

        $voiceGroup = VoiceGroup::where('name', 'Sopran')->firstOrFail();
        $subVoice = SubVoice::where('voice_group_id', $voiceGroup->id)->first();
        if (!$subVoice) {
            $subVoice = SubVoice::create([
                'name' => '1. Sopran',
                'voice_group_id' => $voiceGroup->id,
            ]);
        }
        $this->subVoiceId = (int) $subVoice->id;

        $user = User::create([
            'email' => 'liste_' . bin2hex(random_bytes(4)) . '@example.test',
            'password' => PasswordHasher::hash(bin2hex(random_bytes(8))),
            'first_name' => 'Doris',
            'last_name' => 'Diskant',
            'is_active' => 1,
        ]);
        $this->userId = (int) $user->id;

        $user->voiceGroups()->attach($voiceGroup->id, ['sub_voice_id' => $this->subVoiceId]);

        $project = Project::create(['name' => 'Frühjahrskonzert ' . bin2hex(random_bytes(4))]);
        $user->projects()->attach($project->id);
    }

    protected function tearDown(): void
    {
        Bootstrap::getCapsule()?->connection()->rollBack();
        parent::tearDown();
    }

    public function testDieListeLiefertStimmgruppeMitTeilstimmenKennungImPivot(): void
    {
        $user = $this->listedUser();

        $this->assertTrue($user->relationLoaded('voiceGroups'), 'Die Liste zeigt die Stimmgruppen an.');
        $voiceGroup = $user->voiceGroups->first();
        $this->assertNotNull($voiceGroup);
        $this->assertSame(
            $this->subVoiceId,
            (int) $voiceGroup->pivot->sub_voice_id,
            'Ohne die Kennung im Pivot bliebe die Teilstimme in der Liste leer.'
        );
    }

    public function testDieListeLaedtRollenUndProjekteMit(): void
    {
        $user = $this->listedUser();

        $this->assertTrue($user->relationLoaded('roles'), 'Die Rechteprüfung je Zeile liest die Rollen.');
        $this->assertTrue($user->relationLoaded('projects'), 'Die Spalte "Projekte" liest die Projekte.');
    }

    public function testDieListeLaedtKeineUngenutztenTeilstimmenKetten(): void
    {
        $user = $this->listedUser();

        $this->assertFalse(
            $user->relationLoaded('subVoices'),
            'Die Liste liest die Teilstimmen des Mitglieds nie - sie löst über die Gesamtliste auf.'
        );
        $voiceGroup = $user->voiceGroups->first();
        $this->assertNotNull($voiceGroup);
        $this->assertFalse(
            $voiceGroup->relationLoaded('subVoices'),
            'voiceGroups.subVoices holte alle Teilstimmen aller Stimmgruppen, ohne dass sie jemand liest.'
        );
    }

    public function testDieListeBrauchtHoechstensVierAbfragen(): void
    {
        $connection = Bootstrap::getCapsule()?->connection();
        $this->assertNotNull($connection);

        $connection->flushQueryLog();
        $connection->enableQueryLog();
        (new UserQuery(new NameFormatterService()))->getAllUsers();
        $queries = $connection->getQueryLog();
        $connection->disableQueryLog();

        $this->assertLessThanOrEqual(
            4,
            count($queries),
            'Erwartet: Mitglieder, Rollen, Stimmgruppen, Projekte - mehr liest die Liste nicht. Gelaufen: '
                . implode(' | ', array_column($queries, 'query'))
        );
    }

    private function listedUser(): User
    {
        $users = (new UserQuery(new NameFormatterService()))->getAllUsers();
        $user = $users->firstWhere('id', $this->userId);

        $this->assertInstanceOf(User::class, $user, 'Das angelegte Mitglied fehlt in der Liste.');

        return $user;
    }
}
