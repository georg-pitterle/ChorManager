<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Controllers\SponsorshipController;
use App\Models\Attachment;
use App\Models\Sponsor;
use App\Models\Sponsorship;
use App\Models\User;
use App\Policies\NewsletterPolicy;
use App\Policies\SponsoringPolicy;
use App\Policies\TaskPolicy;
use App\Services\AttachmentAccessRegistry;
use App\Util\PasswordHasher;
use PHPUnit\Framework\TestCase;
use Psr\Http\Message\ResponseInterface;
use ReflectionMethod;
use Tests\Unit\Bootstrap;

/**
 * Antworten aus dem Review-Lauf 38 (src/Policies).
 *
 * Zwei Punkte, beide nach Rückfrage entschieden:
 *
 * 1) NewsletterPolicy::canView() trug einen zweiten Parameter, mit dem sich die
 *    Person überschreiben ließ, für die geprüft wird - durchgereicht von
 *    AttachmentAccessRegistry::mayAccess(). Im Code stand dort überall nur
 *    `$_SESSION['user_id']`, also genau der Wert, den der Konstruktor schon
 *    selbst gelesen hatte; der Parameter änderte nie etwas. Gegen den Fall, dass
 *    doch einmal eine Kennung aus der Anfrage durchgereicht wird, stand nur ein
 *    Kommentar. Jetzt ist er weg: Beide lesen die Sitzung selbst, und die
 *    Rechteprüfung kann nicht mehr für eine fremde Person antworten.
 *
 * 2) Die zuständige Person einer Vereinbarung kam ungeprüft aus dem Formular.
 *    Eine erfundene Kennung lief in den Fremdschlüssel und kam als
 *    nichtssagendes "konnte nicht angelegt werden" zurück. Geprüft wird jetzt
 *    gegen die aktiven Mitglieder - genau die, die das Formular anbietet.
 */
class ReviewAnswersRun38FeatureTest extends TestCase
{
    use TestHttpHelpers;

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
        $connection = Bootstrap::getCapsule()?->connection();
        if ($connection !== null && $connection->transactionLevel() > 0) {
            $connection->rollBack();
        }

        $_SESSION = [];
        parent::tearDown();
    }

    // ------------------------------------------------------------------
    // 1) Keine zweite Identität in der Anhang- und Newsletter-Prüfung
    // ------------------------------------------------------------------

    /**
     * Der Parameter ist der ganze Befund: Solange die Signatur eine zweite
     * Person annimmt, kann ein künftiger Aufruf eine Kennung aus der Anfrage
     * durchreichen, und die Prüfung antwortet für jemand anderen. Dass heute
     * überall der richtige Wert ankommt, prüft kein Test dauerhaft mit - die
     * Signatur schon.
     */
    public function testNeitherCheckAcceptsAnIdentityFromOutside(): void
    {
        $this->assertSame(
            1,
            (new ReflectionMethod(NewsletterPolicy::class, 'canView'))->getNumberOfParameters(),
            'canView() darf nur noch die Newsletter-Kennung annehmen, nicht zusätzlich eine Person.'
        );

        $this->assertSame(
            1,
            (new ReflectionMethod(AttachmentAccessRegistry::class, 'mayAccess'))->getNumberOfParameters(),
            'mayAccess() darf nur noch den Anhang annehmen, nicht zusätzlich eine Person.'
        );
    }

    /**
     * Die Gegenprobe zur Signatur: Die Registry holt die Sitzung über den
     * Konstruktor, nicht aus dem Superglobal - dieselbe Regel, die
     * tests/Unit/Policies/PoliciesReceiveTheSessionTest für die Policies
     * festhält. Ohne sie wanderte der Griff ins Superglobal einfach eine Ebene
     * tiefer und niemandem fiele es auf.
     */
    public function testTheRegistryDoesNotReachIntoTheSessionSuperglobal(): void
    {
        $path = dirname(__DIR__, 2) . '/src/Services/AttachmentAccessRegistry.php';
        $source = (string) file_get_contents($path);

        $offenders = [];
        foreach (explode("\n", $source) as $index => $line) {
            if (str_contains($line, '$_SESSION')) {
                $offenders[] = ($index + 1) . ': ' . trim($line);
            }
        }

        $this->assertSame(
            [],
            $offenders,
            'Die Registry holt sich die Sitzung selbst, statt sie übergeben zu bekommen.'
        );
    }

    /**
     * Und der Nachweis, dass die Sitzung wirklich entscheidet: Dieselbe Registry
     * beantwortet denselben Anhang für zwei Sitzungen verschieden. Ohne diese
     * Prüfung dürfte die Kennung beim Umbau still auf 0 verkümmern - abgelehnt
     * würde dann alles, und ein reiner Ablehnungstest bliebe grün.
     */
    public function testTheSessionAloneDecidesWhoSeesTheSong(): void
    {
        $member = $this->createUser('Mira', 'Mitglied');
        $stranger = $this->createUser('Rudi', 'Fremd');

        $capsule = Bootstrap::getCapsule();
        $this->assertNotNull($capsule);

        $projectId = (int) $capsule->table('projects')->insertGetId([
            'name' => 'Lauf38-Projekt ' . $this->suffix,
        ]);
        $songId = (int) $capsule->table('songs')->insertGetId([
            'title' => 'Lauf38-Lied ' . $this->suffix,
        ]);
        $capsule->table('project_song_assignments')->insert([
            'project_id' => $projectId,
            'song_id' => $songId,
        ]);
        $capsule->table('project_users')->insert([
            'project_id' => $projectId,
            'user_id' => (int) $member->id,
        ]);

        $attachment = new Attachment();
        $attachment->entity_type = 'song';
        $attachment->entity_id = $songId;

        $this->assertTrue(
            $this->registryForSession(['user_id' => (int) $member->id])->mayAccess($attachment),
            'Wer im Projekt singt, kommt an das Notenblatt.'
        );

        $this->assertFalse(
            $this->registryForSession(['user_id' => (int) $stranger->id])->mayAccess($attachment),
            'Wer nicht im Projekt ist, nicht.'
        );
    }

    // ------------------------------------------------------------------
    // 2) Die zuständige Person muss ein aktives Mitglied sein
    // ------------------------------------------------------------------

    public function testAnUnknownAssigneeIsRejectedBeforeTheForeignKey(): void
    {
        $sponsor = $this->createSponsor();

        $result = $this->createSponsorship($sponsor, ['assigned_user_id' => '999999999']);

        $this->assertRedirect($result, '/sponsoring/sponsors/' . $sponsor->id);
        $this->assertSame(SponsorshipController::ASSIGNEE_ERROR, $_SESSION['error'] ?? null);
        $this->assertSame(
            0,
            Sponsorship::where('sponsor_id', $sponsor->id)->count(),
            'Eine abgewiesene Zuständigkeit darf keine Vereinbarung anlegen.'
        );
    }

    /**
     * Das Formular bietet nur aktive Mitglieder an. Ein archiviertes Konto
     * gelangt also nur über eine veraltete oder manipulierte Eingabe hierher.
     */
    public function testAnArchivedMemberCannotBeMadeResponsible(): void
    {
        $sponsor = $this->createSponsor();
        $archived = $this->createUser('Alma', 'Archiviert', isActive: false);

        $result = $this->createSponsorship($sponsor, ['assigned_user_id' => (string) $archived->id]);

        $this->assertRedirect($result, '/sponsoring/sponsors/' . $sponsor->id);
        $this->assertSame(SponsorshipController::ASSIGNEE_ERROR, $_SESSION['error'] ?? null);
        $this->assertSame(0, Sponsorship::where('sponsor_id', $sponsor->id)->count());
    }

    /**
     * Die wichtigere Hälfte: Die Prüfung darf nicht zu einem pauschalen Nein
     * verkümmern. Ohne diese Zusicherung bliebe die Suite grün, auch wenn
     * niemand mehr eine Zuständigkeit eintragen könnte.
     */
    public function testAnActiveMemberIsAcceptedAsResponsible(): void
    {
        $sponsor = $this->createSponsor();
        $active = $this->createUser('Nina', 'Zuständig');

        $result = $this->createSponsorship($sponsor, ['assigned_user_id' => (string) $active->id]);

        $this->assertRedirect($result, '/sponsoring/sponsors/' . $sponsor->id);
        $this->assertNull($_SESSION['error'] ?? null);

        $sponsorship = Sponsorship::where('sponsor_id', $sponsor->id)->firstOrFail();
        $this->assertSame((int) $active->id, (int) $sponsorship->assigned_user_id);
    }

    /**
     * Ein Speichern aus einem anderen Grund - ein korrigierter Betrag - darf die
     * Zuständigkeit nicht still löschen, nur weil die Person inzwischen
     * archiviert wurde. Dieselbe Überlegung wie bei
     * SponsoringPolicy::retainedProjects(), wo ein abgeschlossenes Projekt an
     * der Vereinbarung hängen bleibt.
     */
    public function testAnAlreadyAssignedMemberStaysAssignedAfterBeingArchived(): void
    {
        $sponsor = $this->createSponsor();
        $assignee = $this->createUser('Tom', 'Später');

        $sponsorship = Sponsorship::create([
            'sponsor_id' => $sponsor->id,
            'assigned_user_id' => $assignee->id,
            'created_by_user_id' => $_SESSION['user_id'],
            'amount' => '100.00',
        ]);

        $assignee->is_active = 0;
        $assignee->save();

        $result = $this->controller()->update(
            $this->makeRequest('POST', '/sponsoring/sponsorships/' . $sponsorship->id, [
                'sponsor_id' => (string) $sponsor->id,
                'amount' => '250',
                'assigned_user_id' => (string) $assignee->id,
            ]),
            $this->makeResponse(),
            ['id' => (string) $sponsorship->id]
        );

        $this->assertRedirect($result, '/sponsoring/sponsors/' . $sponsor->id);
        $this->assertNull($_SESSION['error'] ?? null);

        $sponsorship->refresh();
        $this->assertSame(
            (int) $assignee->id,
            (int) $sponsorship->assigned_user_id,
            'Die bereits eingetragene Person bleibt eingetragen.'
        );
        $this->assertSame('250.00', (string) $sponsorship->amount);
    }

    /**
     * Umgekehrt bleibt der Wechsel auf eine erfundene Kennung auch beim Ändern
     * gesperrt - und die bisherige Zuständigkeit unberührt.
     */
    public function testSwitchingToAnUnknownAssigneeLeavesTheAgreementUntouched(): void
    {
        $sponsor = $this->createSponsor();
        $assignee = $this->createUser('Eva', 'Bleibt');

        $sponsorship = Sponsorship::create([
            'sponsor_id' => $sponsor->id,
            'assigned_user_id' => $assignee->id,
            'created_by_user_id' => $_SESSION['user_id'],
            'amount' => '100.00',
        ]);

        $this->controller()->update(
            $this->makeRequest('POST', '/sponsoring/sponsorships/' . $sponsorship->id, [
                'sponsor_id' => (string) $sponsor->id,
                'amount' => '250',
                'assigned_user_id' => '999999999',
            ]),
            $this->makeResponse(),
            ['id' => (string) $sponsorship->id]
        );

        $this->assertSame(SponsorshipController::ASSIGNEE_ERROR, $_SESSION['error'] ?? null);

        $sponsorship->refresh();
        $this->assertSame((int) $assignee->id, (int) $sponsorship->assigned_user_id);
        $this->assertSame('100.00', (string) $sponsorship->amount, 'Auch der Betrag bleibt stehen.');
    }

    // ------------------------------------------------------------------
    // Fixtures
    // ------------------------------------------------------------------

    /**
     * @param array<string, bool|int> $session
     */
    private function registryForSession(array $session): AttachmentAccessRegistry
    {
        return new AttachmentAccessRegistry(
            new SponsoringPolicy($session),
            new TaskPolicy($session),
            ['finance' => true, 'sponsoring' => true, 'tasks' => true, 'newsletter' => true],
            new NewsletterPolicy($session),
            $session
        );
    }

    private function createUser(string $firstName, string $lastName, bool $isActive = true): User
    {
        return User::create([
            // Die Adresse bleibt technisch ASCII und wird deshalb nicht aus dem
            // Namen gebildet. naming:ascii
            'email' => 'run38_' . bin2hex(random_bytes(4)) . '@example.test',
            'password' => PasswordHasher::hash('secret'),
            'first_name' => $firstName,
            'last_name' => $lastName,
            'is_active' => $isActive ? 1 : 0,
        ]);
    }

    /**
     * Die handelnde Person trägt das Vollrecht - geprüft wird hier die
     * Zuständigkeit, nicht die Rechtestufe.
     */
    private function createSponsor(): Sponsor
    {
        $actor = $this->createUser('Sina', 'Handelnd');

        $_SESSION['can_manage_sponsoring'] = true;
        $_SESSION['user_id'] = (int) $actor->id;

        return Sponsor::create([
            'name' => 'Lauf38-Sponsor ' . $this->suffix,
            'created_by_user_id' => $actor->id,
        ]);
    }

    private function controller(): SponsorshipController
    {
        return new SponsorshipController(
            new SponsoringPolicy($_SESSION),
            $this->attachmentService()
        );
    }

    /**
     * @param array<string, string> $extra
     */
    private function createSponsorship(Sponsor $sponsor, array $extra): ResponseInterface
    {
        return $this->controller()->create(
            $this->makeRequest('POST', '/sponsoring/sponsorships', [
                'sponsor_id' => (string) $sponsor->id,
                'amount' => '100',
            ] + $extra),
            $this->makeResponse()
        );
    }
}
