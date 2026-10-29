<?php

declare(strict_types=1);

namespace Tests\Unit\Models;

use App\Models\Activity;
use App\Models\Project;
use App\Models\Task;
use App\Models\User;
use App\Util\PasswordHasher;
use PHPUnit\Framework\TestCase;
use Tests\Unit\Bootstrap;

/**
 * Der Verlauf steht in einer festen Reihenfolge: der neueste Eintrag zuerst.
 *
 * Gleiche Begründung und gleicher Gleichstand-Brecher wie bei den Notizen
 * (CommentRelationOrderingTest): `activities.created_at` ist ein `timestamp`
 * und steht damit auf Sekunden genau. Ohne zweiten Schlüssel entscheidet die
 * Datenbank, welcher von zwei Einträgen derselben Sekunde zuerst kommt - in der
 * Praxis die Einfügereihenfolge, zugesichert ist das aber nicht.
 *
 * Beim Verlauf wiegt das schwerer als bei den Notizen: `task_detail.twig` dreht
 * die Beziehung mit `|reverse` wieder um und zeigt den Verlauf von unten nach
 * oben. Ein Gleichstand steht dort also nicht nur in wechselnder, sondern in
 * verkehrter Reihenfolge - "Status geändert" vor "Aufgabe erstellt".
 *
 * Die Tests legen die Einträge so schnell an, dass sie dieselbe `created_at`
 * tragen, und prüfen das anschließend nach.
 */
final class ActivityRelationOrderingTest extends TestCase
{
    /** @var list<string> */
    private const NEWEST_FIRST = ['Zuletzt noch das.', 'Dann das hier.', 'Zuerst geschehen.'];

    private User $actor;

    private Project $project;

    private Task $task;

    protected function setUp(): void
    {
        parent::setUp();
        Bootstrap::setupTestDatabase();

        $this->actor = User::create([
            'first_name' => 'Ruth',
            'last_name' => 'Öllinger',
            'email' => 'ruth.oe.' . bin2hex(random_bytes(4)) . '@example.test', // naming:ascii - E-Mail bleibt ASCII.
            'password' => PasswordHasher::hash('test123'),
            'is_active' => 1,
        ]);

        $this->project = Project::create([
            'name' => 'Verlaufsreihenfolge ' . bin2hex(random_bytes(3)),
            'description' => 'Projekt für den Reihenfolge-Test',
        ]);

        $this->task = Task::create([
            'project_id' => $this->project->id,
            'name' => 'Noten kopieren',
            'created_by' => $this->actor->id,
        ]);
    }

    protected function tearDown(): void
    {
        Activity::query()->where('user_id', $this->actor->id)->delete();
        $this->task->delete();
        $this->project->delete();
        $this->actor->delete();

        parent::tearDown();
    }

    /**
     * Legt die Einträge ohne eigenes `created_at` an - die Spalte bekommt ihren
     * Standardwert und ist damit für alle drei dieselbe Sekunde.
     */
    private function addActivities(): void
    {
        foreach (array_reverse(self::NEWEST_FIRST) as $description) {
            Activity::create([
                'entity_type' => Task::ENTITY_TYPE,
                'entity_id' => $this->task->id,
                'user_id' => $this->actor->id,
                'action' => 'updated',
                'description' => $description,
            ]);
        }

        $distinctCreatedAt = Activity::query()
            ->where('entity_type', Task::ENTITY_TYPE)
            ->where('entity_id', $this->task->id)
            ->distinct()
            ->pluck('created_at')
            ->all();

        self::assertCount(
            1,
            $distinctCreatedAt,
            'Die Einträge müssen dieselbe created_at tragen, sonst prüft der Test den Gleichstand nicht.'
        );
    }

    public function testDerVerlaufDerAufgabeZeigtDenNeuestenEintragZuerst(): void
    {
        $this->addActivities();

        self::assertSame(self::NEWEST_FIRST, $this->task->activities()->pluck('description')->all());
    }

    public function testDerVerlaufDerPersonZeigtDenNeuestenEintragZuerst(): void
    {
        $this->addActivities();

        self::assertSame(self::NEWEST_FIRST, $this->actor->activities()->pluck('description')->all());
    }
}
