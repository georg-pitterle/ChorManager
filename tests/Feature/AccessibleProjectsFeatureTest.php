<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\Project;
use App\Models\User;
use App\Queries\ProjectQuery;
use App\Services\NameFormatterService;
use Illuminate\Database\Capsule\Manager as Capsule;
use Illuminate\Database\Eloquent\Collection;
use PHPUnit\Framework\TestCase;
use Tests\Unit\Bootstrap;

/**
 * Deckt die Projektauswahl ab, die Termine und Auswertungen gemeinsam nutzen.
 * Bisher wurde sie nur gemockt oder über den Quelltext geprüft - dass die
 * Einschränkung auf eigene Projekte tatsächlich greift und eine Sitzung ohne
 * Kennung leer ausgeht, war damit ungetestet.
 */
class AccessibleProjectsFeatureTest extends TestCase
{
    private int $ownProjectId = 0;

    private int $foreignProjectId = 0;

    private int $userId = 0;

    protected function setUp(): void
    {
        parent::setUp();
        Bootstrap::setupTestDatabase();
        Bootstrap::getCapsule()?->connection()->beginTransaction();

        // Zufälliges Namensfragment: die Testdatenbank kann bereits Projekte enthalten,
        // die Zusicherungen filtern deshalb auf genau diese beiden.
        $suffix = bin2hex(random_bytes(4));

        // "Adventkonzert" vor "Zwischenkonzert" - die Abfrage sortiert nach Namen.
        $this->ownProjectId = (int) Project::create(['name' => 'Adventkonzert ' . $suffix])->id;
        $this->foreignProjectId = (int) Project::create(['name' => 'Zwischenkonzert ' . $suffix])->id;

        $user = User::create([
            'email' => 'accessible_' . $suffix . '@example.test',
            'password' => password_hash('secret', PASSWORD_BCRYPT),
            'first_name' => 'Hanna',
            'last_name' => 'Hauser',
            'is_active' => 1,
        ]);
        $this->userId = (int) $user->id;

        Capsule::table('project_users')->insert([
            'user_id' => $this->userId,
            'project_id' => $this->ownProjectId,
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

    private function query(): ProjectQuery
    {
        return new ProjectQuery(new NameFormatterService());
    }

    /**
     * Reduziert das Ergebnis auf die beiden hier angelegten Projekte, damit
     * Bestandsdaten der Testdatenbank die Zusicherungen nicht verfälschen.
     *
     * @return list<int>
     */
    private function knownProjectIdsIn(Collection $projects): array
    {
        $known = [$this->ownProjectId, $this->foreignProjectId];

        return array_values(array_filter(
            array_map(static fn ($project): int => (int) $project->id, $projects->all()),
            static fn (int $id): bool => in_array($id, $known, true)
        ));
    }

    public function testMemberSeesOnlyOwnProjects(): void
    {
        $projects = $this->query()->getAccessibleProjects($this->userId, false);

        $this->assertSame(
            [$this->ownProjectId],
            $this->knownProjectIdsIn($projects),
            'Ohne übergreifendes Recht bleibt die Auswahl auf die eigene Zuordnung beschränkt.'
        );
    }

    public function testBroadRightAlsoSeesForeignProjects(): void
    {
        $projects = $this->query()->getAccessibleProjects($this->userId, true);

        $this->assertSame(
            [$this->ownProjectId, $this->foreignProjectId],
            $this->knownProjectIdsIn($projects),
            'Mit übergreifendem Recht stehen alle Projekte zur Auswahl, nach Namen sortiert.'
        );
    }

    public function testSessionWithoutUserIdStaysEmpty(): void
    {
        $projects = $this->query()->getAccessibleProjects(0, false);

        $this->assertCount(
            0,
            $projects,
            'Fehlt die Sitzungskennung, ist die leere Liste die sichere Richtung.'
        );
    }
}
