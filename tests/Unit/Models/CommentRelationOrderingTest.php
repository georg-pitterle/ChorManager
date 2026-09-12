<?php

declare(strict_types=1);

namespace Tests\Unit\Models;

use App\Models\Comment;
use App\Models\Event;
use App\Models\Project;
use App\Models\Task;
use App\Models\User;
use App\Util\PasswordHasher;
use PHPUnit\Framework\TestCase;
use Tests\Unit\Bootstrap;

/**
 * Notizen kommen überall in derselben Reihenfolge: die neueste zuerst.
 *
 * Beim Termin galt das schon, bei Aufgabe und Projekt fehlte die Sortierung
 * ganz. Ohne `ORDER BY` entscheidet die Datenbank, was sie zurückgibt - in der
 * Praxis die Einfügereihenfolge, zugesichert ist das aber nicht. Aufgabe,
 * Projekt und Termin teilen sich mit `partials/comments.twig` dieselbe
 * Darstellung; sie dürfen nicht unterschiedlich herum stehen.
 *
 * Der zweite Schlüssel `id` ist kein Beiwerk. `comments.created_at` steht auf
 * Sekunden genau, und zwei Notizen in derselben Sekunde sind keine Ausnahme -
 * ein Import legt reihenweise welche an. Ohne den Gleichstand-Brecher wechselt
 * ihre Reihenfolge zwischen zwei Aufrufen, und genau das prüfen die Tests hier:
 * Sie legen die Notizen so schnell an, dass sie dieselbe `created_at` tragen.
 */
final class CommentRelationOrderingTest extends TestCase
{
    /** @var list<string> */
    private const NEWEST_FIRST = ['Zuletzt noch das.', 'Dann das hier.', 'Zuerst geschrieben.'];

    private User $author;

    private Project $project;

    private Task $task;

    private Event $event;

    protected function setUp(): void
    {
        parent::setUp();
        Bootstrap::setupTestDatabase();

        $this->author = User::create([
            'first_name' => 'Ingrid',
            'last_name' => 'Größlmayr',
            'email' => 'ingrid.g.' . bin2hex(random_bytes(4)) . '@example.test', // naming:ascii - E-Mail bleibt ASCII.
            'password' => PasswordHasher::hash('test123'),
            'is_active' => 1,
        ]);

        $this->project = Project::create([
            'name' => 'Frühlingskonzert ' . bin2hex(random_bytes(3)),
            'description' => 'Projekt für den Reihenfolge-Test',
        ]);

        $this->task = Task::create([
            'project_id' => $this->project->id,
            'name' => 'Programmheft setzen',
            'created_by' => $this->author->id,
        ]);

        $this->event = Event::create([
            'title' => 'Probe für den Reihenfolge-Test',
            'starts_at' => '2026-05-12 19:00:00',
            'ends_at' => '2026-05-12 21:00:00',
            'type' => 'rehearsal',
        ]);
    }

    protected function tearDown(): void
    {
        Comment::query()
            ->whereIn('entity_type', ['task', 'project', 'event'])
            ->whereIn('entity_id', [$this->task->id, $this->project->id, $this->event->id])
            ->delete();
        $this->event->delete();
        $this->task->delete();
        $this->project->delete();
        $this->author->delete();

        parent::tearDown();
    }

    /**
     * Legt die Notizen ohne eigenes `created_at` an - die Spalte bekommt ihren
     * Standardwert und ist damit für alle drei dieselbe Sekunde. Der Test prüft
     * das anschließend, sonst liefe er am Gleichstand vorbei.
     */
    private function addComments(string $entityType, int $entityId): void
    {
        foreach (array_reverse(self::NEWEST_FIRST) as $text) {
            Comment::create([
                'entity_type' => $entityType,
                'entity_id' => $entityId,
                'user_id' => $this->author->id,
                'comment' => $text,
                'is_private' => false,
            ]);
        }

        $distinctCreatedAt = Comment::query()
            ->where('entity_type', $entityType)
            ->where('entity_id', $entityId)
            ->distinct()
            ->pluck('created_at')
            ->all();

        self::assertCount(
            1,
            $distinctCreatedAt,
            'Die Notizen müssen dieselbe created_at tragen, sonst prüft der Test den Gleichstand nicht.'
        );
    }

    public function testDieAufgabeZeigtDieNeuesteNotizZuerst(): void
    {
        $this->addComments('task', $this->task->id);

        self::assertSame(self::NEWEST_FIRST, $this->task->comments()->pluck('comment')->all());
    }

    public function testDasProjektZeigtDieNeuesteNotizZuerst(): void
    {
        $this->addComments('project', $this->project->id);

        self::assertSame(self::NEWEST_FIRST, $this->project->comments()->pluck('comment')->all());
    }

    public function testDerTerminZeigtDieNeuesteNotizZuerst(): void
    {
        $this->addComments('event', $this->event->id);

        self::assertSame(self::NEWEST_FIRST, $this->event->comments()->pluck('comment')->all());
    }
}
