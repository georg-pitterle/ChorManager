<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\Category;
use App\Models\Project;
use App\Models\Song;
use Illuminate\Database\Capsule\Manager as Capsule;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use Tests\Unit\Bootstrap;

/**
 * Die Liedübersicht lädt nur, was sie anzeigt.
 *
 * Sie holte bis hierher zweierlei umsonst: die Projekte hinter den Zuordnungen
 * jedes Liedes, obwohl `songs/manage.twig` nur deren Anzahl ausgibt
 * (`song.projectAssignments|length`), und zusätzlich sämtliche Projekte samt
 * ihren Liedern und deren Anhängen - eine Übergabe, die das Template seit der
 * Umstellung auf `songs` nicht mehr liest.
 *
 * Gemessen wird die Zahl der Abfragen der Seite. Eine absolute Schranke ist hier
 * die ehrliche Form: Die Übersicht beantwortet eine feste Frage, ihre Abfragen
 * sind abzählbar, und eine zusätzliche fällt nur dann auf, wenn die Zahl
 * dasteht. Zusätzlich darf die Zahl nicht mit dem Bestand wachsen - weder mit
 * den Liedern noch mit den Projekten.
 */
final class SongLibraryOverviewQueryCountFeatureTest extends TestCase
{
    use TestHttpHelpers;
    use TwigViewStubs;
    use BuildsControllers;

    /**
     * Lieder, Kategorien, Anhänge und Zuordnungen - vier Abfragen für die
     * Übersicht, dazu die Kategorien der Filterleiste.
     */
    private const MAX_QUERIES = 5;

    protected function setUp(): void
    {
        parent::setUp();
        Bootstrap::setupTestDatabase();
        Capsule::connection()->beginTransaction();

        $_SESSION = ['user_id' => 1, 'can_manage_song_library' => true];
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

    public function testTheOverviewStaysWithinItsQueryBudget(): void
    {
        $this->seedLibrary(2);

        self::assertLessThanOrEqual(self::MAX_QUERIES, $this->countOverviewQueries());
    }

    public function testTheQueryCountDoesNotGrowWithTheLibrary(): void
    {
        $this->seedLibrary(2);
        $small = $this->countOverviewQueries();

        $this->seedLibrary(8);
        $large = $this->countOverviewQueries();

        self::assertSame($small, $large);
    }

    private function countOverviewQueries(): int
    {
        $controller = $this->songLibraryController([
            'view' => $this->createAppTwig('/song-library'),
            'logger' => new NullLogger(),
        ]);

        $connection = Capsule::connection();
        $connection->flushQueryLog();
        $connection->enableQueryLog();

        try {
            $controller->index($this->makeRequest('GET', '/song-library'), $this->makeResponse());
        } finally {
            $count = count($connection->getQueryLog());
            $connection->disableQueryLog();
            $connection->flushQueryLog();
        }

        return $count;
    }

    /** Lieder mit Kategorie und Projektzuordnung - der Normalfall der Übersicht. */
    private function seedLibrary(int $count): void
    {
        $tag = bin2hex(random_bytes(4));
        $category = Category::create(['name' => 'Zaehlkategorie ' . $tag, 'sort_order' => 900000]);

        for ($index = 0; $index < $count; $index++) {
            $song = Song::create(['title' => 'Zaehllied ' . $tag . ' ' . $index]);
            $song->categories()->sync([(int) $category->id]);

            $project = Project::create([
                'name' => 'Zaehlprojekt ' . $tag . ' ' . $index,
                'start_date' => '2027-01-0' . (($index % 9) + 1),
            ]);
            $project->assignedSongs()->attach((int) $song->id);
        }
    }
}
