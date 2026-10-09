<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\Project;
use App\Queries\ProjectQuery;
use App\Services\NameFormatterService;
use Carbon\Carbon;
use PHPUnit\Framework\TestCase;
use Tests\Unit\Bootstrap;

/**
 * Auswertungen sollen ohne project_id-Parameter das aktuell laufende Projekt
 * vorauswählen und erst danach auf die zuletzt gewählte Auswahl
 * (users.last_project_id) zurückfallen.
 *
 * "Laufend" heißt: Das Startdatum ist erreicht, und das Enddatum ist entweder
 * noch nicht überschritten oder gar nicht gesetzt. Ein leeres Enddatum liest
 * sich in der Oberfläche als "noch nicht festgelegt", nicht als "schon vorbei".
 */
class EvaluationDefaultProjectFeatureTest extends TestCase
{
    use EventScopeFixtures;

    private ProjectQuery $projectQuery;

    protected function setUp(): void
    {
        Bootstrap::setupTestDatabase();
        $this->beginFixtureTransaction();

        $this->projectQuery = new ProjectQuery(new NameFormatterService('last_first'));
    }

    protected function tearDown(): void
    {
        $this->rollBackFixtureTransaction();
        parent::tearDown();
    }

    public function testReturnsRunningProjectFromAccessibleProjects(): void
    {
        $past = $this->createProject('-12 months', '-6 months');
        $running = $this->createProject('-1 month', '+1 month');
        $future = $this->createProject('+2 months', '+8 months');

        $accessible = [(int) $past->id, (int) $running->id, (int) $future->id];

        $this->assertSame(
            (int) $running->id,
            $this->projectQuery->findCurrentProjectId($accessible)
        );
    }

    /**
     * Laufen mehrere Projekte parallel, gewinnt das zuletzt gestartete - dasselbe,
     * das in der Projektliste darüber an erster Stelle steht. Vorher gewann das
     * zuerst endende; Liste und Vorauswahl zeigten damit auf verschiedene Projekte.
     */
    public function testReturnsMostRecentlyStartedProjectWhenSeveralRun(): void
    {
        $startedEarlierEndsSooner = $this->createProject('-2 months', '+1 month');
        $startedLaterEndsLater = $this->createProject('-1 month', '+6 months');

        $accessible = [(int) $startedEarlierEndsSooner->id, (int) $startedLaterEndsLater->id];

        $this->assertSame(
            (int) $startedLaterEndsLater->id,
            $this->projectQuery->findCurrentProjectId($accessible)
        );
    }

    /**
     * Die Vorauswahl ist genau der erste Eintrag der Projektliste, sofern der
     * gerade läuft - dieselbe Reihenfolge, dieselbe Quelle.
     */
    public function testPreselectionMatchesTheTopOfTheProjectList(): void
    {
        $running = [
            $this->createProject('-3 months', '+2 months'),
            $this->createProject('-1 month', '+1 month'),
            $this->createProject('-2 months', '+9 months'),
        ];

        $accessible = array_map(static fn(Project $project): int => (int) $project->id, $running);
        $listed = $this->projectQuery->getProjectsByIds($accessible);

        $this->assertSame(
            (int) $listed->first()->id,
            $this->projectQuery->findCurrentProjectId($accessible)
        );
    }

    public function testIgnoresRunningProjectOutsideAccessibleProjects(): void
    {
        $running = $this->createProject('-1 month', '+1 month');
        $accessibleOnly = $this->createProject('-12 months', '-6 months');

        $this->assertSame(
            0,
            $this->projectQuery->findCurrentProjectId([(int) $accessibleOnly->id])
        );
        $this->assertNotSame(0, (int) $running->id);
    }

    public function testReturnsZeroWithoutRunningProjectOrAccessibleProjects(): void
    {
        $past = $this->createProject('-12 months', '-6 months');

        $this->assertSame(0, $this->projectQuery->findCurrentProjectId([(int) $past->id]));
        $this->assertSame(0, $this->projectQuery->findCurrentProjectId([]));
    }

    public function testProjectsWithoutDatesAreNeverTreatedAsRunning(): void
    {
        $open = Project::create([
            'name' => 'Ohne Zeitraum ' . bin2hex(random_bytes(4)),
            'start_date' => null,
            'end_date' => null,
        ]);

        $this->assertSame(0, $this->projectQuery->findCurrentProjectId([(int) $open->id]));
    }

    /**
     * Ein begonnenes Projekt ohne Enddatum läuft.
     *
     * Die Abfrage verlangte zuvor ein gesetztes Enddatum und ließ ein solches
     * Projekt deshalb nie vorauswählen - obwohl beide Spalten nullable sind und
     * das Projektformular das Enddatum leer lässt. Für ein Chorprojekt ohne
     * festes Ende stand damit keine Vorauswahl bereit.
     */
    public function testStartedProjectWithoutEndDateIsRunning(): void
    {
        $open = $this->createOpenEndedProject('-1 month');

        $this->assertSame(
            (int) $open->id,
            $this->projectQuery->findCurrentProjectId([(int) $open->id])
        );
    }

    /**
     * Das offene Ende verschiebt den Anfang nicht: Vor dem Startdatum läuft auch
     * ein Projekt ohne Enddatum nicht.
     */
    public function testFutureProjectWithoutEndDateIsNotRunning(): void
    {
        $future = $this->createOpenEndedProject('+2 months');

        $this->assertSame(0, $this->projectQuery->findCurrentProjectId([(int) $future->id]));
    }

    /**
     * Gegen ein begrenztes Projekt gewinnt weiter das zuletzt gestartete - das
     * offene Ende ist kein Vorrang, nur eine zweite Art, laufend zu sein.
     */
    public function testTheMostRecentlyStartedWinsAgainstAnOpenEndedProject(): void
    {
        $openStartedEarlier = $this->createOpenEndedProject('-3 months');
        $boundedStartedLater = $this->createProject('-1 month', '+1 month');

        $accessible = [(int) $openStartedEarlier->id, (int) $boundedStartedLater->id];

        $this->assertSame(
            (int) $boundedStartedLater->id,
            $this->projectQuery->findCurrentProjectId($accessible)
        );
    }

    /**
     * Die Eingrenzung auf die zugänglichen Projekte gilt unverändert. Die
     * ODER-Bedingung für das Enddatum muss dafür geklammert bleiben - ohne
     * Klammer bräche sie aus der UND-Kette aus und ein offenes Projekt käme
     * auch dann zurück, wenn es gar nicht zugänglich ist.
     */
    public function testAnOpenEndedProjectOutsideTheAccessibleOnesStaysOut(): void
    {
        $openButForeign = $this->createOpenEndedProject('-1 month');
        $accessiblePast = $this->createProject('-12 months', '-6 months');

        $this->assertSame(
            0,
            $this->projectQuery->findCurrentProjectId([(int) $accessiblePast->id])
        );
        $this->assertNotSame(0, (int) $openButForeign->id);
    }

    private function createOpenEndedProject(string $startModifier): Project
    {
        return Project::create([
            'name' => 'Offenes Projekt ' . bin2hex(random_bytes(4)),
            'start_date' => Carbon::now()->modify($startModifier)->toDateString(),
            'end_date' => null,
        ]);
    }

    private function createProject(string $startModifier, string $endModifier): Project
    {
        return Project::create([
            'name' => 'Auswertungs-Projekt ' . bin2hex(random_bytes(4)),
            'start_date' => Carbon::now()->modify($startModifier)->toDateString(),
            'end_date' => Carbon::now()->modify($endModifier)->toDateString(),
        ]);
    }

    public function testEvaluationControllerPrefersRunningProjectOverLastSelection(): void
    {
        $controller = file_get_contents(dirname(__DIR__) . '/../src/Controllers/EvaluationController.php');
        $this->assertIsString($controller);

        // Beide Auswertungs-Seiten müssen dieselbe Vorauswahl-Logik nutzen.
        $this->assertSame(
            2,
            substr_count($controller, '$projectId = $this->resolveDefaultProjectId($accessibleProjectIds, $userId);')
        );
        $this->assertStringNotContainsString('$projectId = $user->last_project_id;', $controller);

        $resolverStart = strpos($controller, 'private function resolveDefaultProjectId(');
        $this->assertIsInt($resolverStart);

        $resolver = substr($controller, $resolverStart);
        $currentPos = strpos($resolver, 'findCurrentProjectId($accessibleProjectIds)');
        $lastPos = strpos($resolver, 'last_project_id');

        $this->assertIsInt($currentPos);
        $this->assertIsInt($lastPos);
        $this->assertLessThan($lastPos, $currentPos, 'Laufendes Projekt muss vor last_project_id greifen.');
    }
}
