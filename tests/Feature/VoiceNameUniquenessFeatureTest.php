<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Controllers\VoiceGroupController;
use App\Models\SubVoice;
use App\Models\VoiceGroup;
use Illuminate\Database\QueryException;
use PHPUnit\Framework\TestCase;
use Slim\Psr7\Factory\ServerRequestFactory;
use Slim\Psr7\Response;
use Slim\Views\Twig;
use Tests\Unit\Bootstrap;
use Twig\Loader\ArrayLoader;

/**
 * Stimmgruppen- und Teilstimmennamen sind eindeutig.
 *
 * Die Besetzungsansicht der Auswertungen gruppiert nach dem Namen der Stimmgruppe
 * (ProjectQuery::getProjectMembersGroupedByVoice() nimmt ihn als Array-Schlüssel).
 * Gäbe es "Sopran" zweimal, stünden die Mitglieder beider Gruppen unter einer
 * Überschrift und die zweite Gruppe verschwände aus der Anzeige - ohne Fehler,
 * ohne Hinweis. Dasselbe gilt für zwei gleichnamige Teilstimmen einer Gruppe.
 *
 * Gesichert wird das an zwei Stellen, und dieser Test prüft beide: der eindeutige
 * Index aus Migration 20260914120000 als Garantie, und die Prüfung im Controller,
 * damit der Anwender einen deutschen Satz liest statt eines durchgereichten
 * SQL-Fehlers.
 */
class VoiceNameUniquenessFeatureTest extends TestCase
{
    private string $suffix = '';

    protected function setUp(): void
    {
        parent::setUp();
        Bootstrap::setupTestDatabase();
        Bootstrap::getCapsule()?->connection()->beginTransaction();

        $_SESSION = [];
        $this->suffix = bin2hex(random_bytes(4));
    }

    protected function tearDown(): void
    {
        Bootstrap::getCapsule()?->connection()->rollBack();
        $_SESSION = [];

        parent::tearDown();
    }

    public function testDatabaseRejectsDuplicateVoiceGroupName(): void
    {
        $name = 'Probe-Stimmgruppe ' . $this->suffix;
        VoiceGroup::create(['name' => $name]);

        $this->expectException(QueryException::class);
        VoiceGroup::create(['name' => $name]);
    }

    public function testDatabaseRejectsDuplicateSubVoiceNameInSameGroup(): void
    {
        $group = VoiceGroup::create(['name' => 'Probe-Gruppe ' . $this->suffix]);
        $name = 'Probe-Teilstimme ' . $this->suffix;
        SubVoice::create(['name' => $name, 'voice_group_id' => $group->id]);

        $this->expectException(QueryException::class);
        SubVoice::create(['name' => $name, 'voice_group_id' => $group->id]);
    }

    /**
     * Der Index sitzt auf (voice_group_id, name), nicht auf name allein: "Sopran 1"
     * und "Alt 1" sollen weiterhin nebeneinander bestehen können, und eine schlicht
     * mit "1" benannte Teilstimme in jeder Gruppe ebenso.
     */
    public function testSameSubVoiceNameIsAllowedInDifferentGroups(): void
    {
        $first = VoiceGroup::create(['name' => 'Probe-Gruppe A ' . $this->suffix]);
        $second = VoiceGroup::create(['name' => 'Probe-Gruppe B ' . $this->suffix]);
        $name = 'Erste Lage ' . $this->suffix;

        SubVoice::create(['name' => $name, 'voice_group_id' => $first->id]);
        SubVoice::create(['name' => $name, 'voice_group_id' => $second->id]);

        $this->assertSame(2, SubVoice::where('name', $name)->count());
    }

    public function testCreateGroupRejectsExistingNameWithReadableMessage(): void
    {
        $name = 'Doppelt ' . $this->suffix;
        VoiceGroup::create(['name' => $name]);

        $this->controller()->createGroup($this->postRequest(['name' => $name]), new Response());

        $this->assertSame('Es gibt bereits eine Stimmgruppe mit diesem Namen.', $_SESSION['error'] ?? null);
        $this->assertSame(1, VoiceGroup::where('name', $name)->count());
    }

    public function testUpdateGroupRejectsNameOfAnotherGroup(): void
    {
        $taken = VoiceGroup::create(['name' => 'Belegt ' . $this->suffix]);
        $edited = VoiceGroup::create(['name' => 'Frei ' . $this->suffix]);

        $this->controller()->updateGroup(
            $this->postRequest(['name' => (string) $taken->name]),
            new Response(),
            ['id' => (string) $edited->id]
        );

        $this->assertSame('Es gibt bereits eine Stimmgruppe mit diesem Namen.', $_SESSION['error'] ?? null);
        $this->assertSame('Frei ' . $this->suffix, (string) $edited->fresh()->name);
    }

    /**
     * Der eigene Name darf bleiben: wer nur die Schreibweise ändert oder gar nichts,
     * darf nicht an der eigenen Zeile scheitern.
     */
    public function testUpdateGroupAcceptsItsOwnUnchangedName(): void
    {
        $group = VoiceGroup::create(['name' => 'Bleibt ' . $this->suffix]);

        $this->controller()->updateGroup(
            $this->postRequest(['name' => (string) $group->name]),
            new Response(),
            ['id' => (string) $group->id]
        );

        $this->assertSame('Stimmgruppe erfolgreich aktualisiert.', $_SESSION['success'] ?? null);
        $this->assertArrayNotHasKey('error', $_SESSION);
    }

    public function testCreateSubVoiceRejectsExistingNameInSameGroup(): void
    {
        $group = VoiceGroup::create(['name' => 'Gruppe ' . $this->suffix]);
        $name = 'Lage ' . $this->suffix;
        SubVoice::create(['name' => $name, 'voice_group_id' => $group->id]);

        $this->controller()->createSubVoice(
            $this->postRequest(['name' => $name]),
            new Response(),
            ['id' => (string) $group->id]
        );

        $this->assertSame(
            'Es gibt in dieser Stimmgruppe bereits eine Unterstimme mit diesem Namen.',
            $_SESSION['error'] ?? null
        );
        $this->assertSame(1, SubVoice::where('voice_group_id', $group->id)->where('name', $name)->count());
    }

    public function testUpdateSubVoiceRejectsNameOfSiblingInSameGroup(): void
    {
        $group = VoiceGroup::create(['name' => 'Gruppe ' . $this->suffix]);
        $taken = SubVoice::create(['name' => 'Belegte Lage ' . $this->suffix, 'voice_group_id' => $group->id]);
        $edited = SubVoice::create(['name' => 'Freie Lage ' . $this->suffix, 'voice_group_id' => $group->id]);

        $this->controller()->updateSubVoice(
            $this->postRequest(['name' => (string) $taken->name]),
            new Response(),
            ['sub_id' => (string) $edited->id]
        );

        $this->assertSame(
            'Es gibt in dieser Stimmgruppe bereits eine Unterstimme mit diesem Namen.',
            $_SESSION['error'] ?? null
        );
        $this->assertSame('Freie Lage ' . $this->suffix, (string) $edited->fresh()->name);
    }

    /**
     * Eine gleichnamige Teilstimme in einer anderen Gruppe blockiert nicht: die
     * Prüfung im Controller muss dieselbe Grenze ziehen wie der Index.
     */
    public function testUpdateSubVoiceAcceptsNameUsedInAnotherGroup(): void
    {
        $ownGroup = VoiceGroup::create(['name' => 'Eigene Gruppe ' . $this->suffix]);
        $otherGroup = VoiceGroup::create(['name' => 'Fremde Gruppe ' . $this->suffix]);
        $name = 'Gemeinsame Lage ' . $this->suffix;

        SubVoice::create(['name' => $name, 'voice_group_id' => $otherGroup->id]);
        $edited = SubVoice::create(['name' => 'Alte Lage ' . $this->suffix, 'voice_group_id' => $ownGroup->id]);

        $this->controller()->updateSubVoice(
            $this->postRequest(['name' => $name]),
            new Response(),
            ['sub_id' => (string) $edited->id]
        );

        $this->assertSame('Unterstimme erfolgreich aktualisiert.', $_SESSION['success'] ?? null);
        $this->assertSame($name, (string) $edited->fresh()->name);
    }

    private function controller(): VoiceGroupController
    {
        // Die geprüften Methoden leiten nur weiter und rendern nichts; ein leerer
        // Lader genügt deshalb und hält den Test von den echten Vorlagen fern.
        return new VoiceGroupController(new Twig(new ArrayLoader([])));
    }

    /**
     * @param array<string, string> $body
     */
    private function postRequest(array $body): \Psr\Http\Message\ServerRequestInterface
    {
        return (new ServerRequestFactory())
            ->createServerRequest('POST', '/voice-groups')
            ->withParsedBody($body);
    }
}
