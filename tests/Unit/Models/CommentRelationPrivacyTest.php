<?php

declare(strict_types=1);

namespace Tests\Unit\Models;

use App\Models\Comment;
use App\Models\Project;
use App\Models\Task;
use App\Models\User;
use PHPUnit\Framework\TestCase;
use Tests\Unit\Bootstrap;

/**
 * Private Notizen gehören der Person, die sie geschrieben hat.
 *
 * Die Relationen `comments()` kennen die angemeldete Person nicht. Sie können
 * eine fremde private Notiz deshalb nicht von der eigenen unterscheiden - und
 * geben nur den öffentlichen Teil heraus. Wer auch die eigenen privaten Notizen
 * braucht, setzt die Relation mit `Comment::visibleTo()` bewusst selbst, so wie
 * es der EventController tut.
 *
 * Beim Termin galt das von Anfang an, bei Aufgabe und Projekt nicht: Dort lief
 * jede private Notiz mit, sobald es eine gab. Die Anzahl aus
 * `withCount('comments')` in der Aufgabenliste hätte fremde private Notizen
 * sogar mitgerechnet.
 */
final class CommentRelationPrivacyTest extends TestCase
{
    private User $author;

    private Project $project;

    private Task $task;

    protected function setUp(): void
    {
        parent::setUp();
        Bootstrap::setupTestDatabase();

        $this->author = User::create([
            'first_name' => 'Rosa',
            'last_name' => 'Hangöbl',
            // naming:ascii - E-Mail-Adressen bleiben technisch ASCII.
            'email' => 'rosa.hangoebl.' . bin2hex(random_bytes(4)) . '@example.test',
            'password' => password_hash('test123', PASSWORD_DEFAULT),
            'is_active' => 1,
        ]);

        $this->project = Project::create([
            'name' => 'Adventkonzert ' . bin2hex(random_bytes(3)),
            'description' => 'Projekt für den Notiz-Test',
        ]);

        $this->task = Task::create([
            'project_id' => $this->project->id,
            'name' => 'Notenmappen richten',
            'created_by' => $this->author->id,
        ]);
    }

    protected function tearDown(): void
    {
        Comment::query()
            ->whereIn('entity_type', ['task', 'project'])
            ->whereIn('entity_id', [$this->task->id, $this->project->id])
            ->delete();
        $this->task->delete();
        $this->project->delete();
        $this->author->delete();

        parent::tearDown();
    }

    private function addComment(string $entityType, int $entityId, string $text, bool $isPrivate): void
    {
        Comment::create([
            'entity_type' => $entityType,
            'entity_id' => $entityId,
            'user_id' => $this->author->id,
            'comment' => $text,
            'is_private' => $isPrivate,
        ]);
    }

    public function testDieAufgabeVerbirgtPrivateNotizen(): void
    {
        $this->addComment('task', $this->task->id, 'Mappen stehen im Probenraum.', false);
        $this->addComment('task', $this->task->id, 'Mit der Chorleitung noch abklären.', true);

        $notes = $this->task->comments()->pluck('comment')->all();

        self::assertSame(['Mappen stehen im Probenraum.'], $notes);
    }

    public function testDieAnzahlDerNotizenIgnoriertPrivate(): void
    {
        $this->addComment('task', $this->task->id, 'Mappen stehen im Probenraum.', false);
        $this->addComment('task', $this->task->id, 'Mit der Chorleitung noch abklären.', true);

        $counted = Task::query()->whereKey($this->task->id)->withCount('comments')->firstOrFail();

        self::assertSame(1, (int) $counted->comments_count);
    }

    public function testDasProjektVerbirgtPrivateNotizen(): void
    {
        $this->addComment('project', $this->project->id, 'Programm steht.', false);
        $this->addComment('project', $this->project->id, 'Honorarfrage offen.', true);

        $notes = $this->project->comments()->pluck('comment')->all();

        self::assertSame(['Programm steht.'], $notes);
    }

    /**
     * Der bewusste Weg zu den eigenen privaten Notizen bleibt offen.
     */
    public function testVisibleToLiefertDieEigenenPrivatenNotizenWeiterhin(): void
    {
        $this->addComment('task', $this->task->id, 'Mappen stehen im Probenraum.', false);
        $this->addComment('task', $this->task->id, 'Mit der Chorleitung noch abklären.', true);

        $notes = Comment::query()
            ->where('entity_type', 'task')
            ->where('entity_id', $this->task->id)
            ->visibleTo($this->author->id)
            ->orderBy('id')
            ->pluck('comment')
            ->all();

        self::assertSame(
            ['Mappen stehen im Probenraum.', 'Mit der Chorleitung noch abklären.'],
            $notes
        );
    }
}
