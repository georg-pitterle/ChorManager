<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\Project;
use App\Models\Task;
use App\Models\User;
use App\Util\PasswordHasher;
use Illuminate\Database\Capsule\Manager as Capsule;
use PHPUnit\Framework\TestCase;
use Tests\Unit\Bootstrap;

/**
 * Wird ein Mitglied endgültig gelöscht, bleiben die von ihm angelegten Aufgaben
 * stehen - ohne Ersteller, aber mit Titel, Projekt und Zuweisungen.
 *
 * `tasks.created_by` zeigte mit ON DELETE CASCADE auf `users` und war damit die
 * einzige Ersteller-Spalte im Schema, die ihre Zeile mitnimmt: Dateien,
 * Umfragen, Lieder, Sponsoren und Stimmgruppen-Listen setzen den Ersteller auf
 * NULL, Newsletter blockieren die Löschung. Heute fällt das nicht auf, weil
 * Mitglieder nur archiviert werden - `UserPersistence::delete()` steht aber
 * bereit und ist im Kommentar ausdrücklich für eine spätere DSGVO-Löschung
 * vorgesehen. Dann hätte das Löschen eines Mitglieds die Aufgaben aller
 * Beteiligten mitgenommen, auch die, die anderen zugewiesen sind. Gefunden im
 * Review-Lauf 83 (db/migrations), so entschieden in der Nacharbeit.
 */
final class TaskSurvivesCreatorDeletionFeatureTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        Bootstrap::setupTestDatabase();
        Capsule::connection()->beginTransaction();
    }

    protected function tearDown(): void
    {
        $connection = Capsule::connection();
        if ($connection->transactionLevel() > 0) {
            $connection->rollBack();
        }

        parent::tearDown();
    }

    public function testDeletingTheCreatorLeavesTheTaskWithoutACreator(): void
    {
        $suffix = bin2hex(random_bytes(4));

        $project = Project::create([
            'name' => 'Aufgaben-Ersteller ' . $suffix,
            'description' => 'Projekt für die Löschprüfung',
        ]);

        $creator = User::create([
            'first_name' => 'Anna',
            'last_name' => 'Anlegerin',
            'email' => 'task.creator.' . $suffix . '@example.test',
            'password' => PasswordHasher::hash('irrelevant'),
            'is_active' => 1,
        ]);

        $assignee = User::create([
            'first_name' => 'Bernd',
            'last_name' => 'Bearbeiter',
            'email' => 'task.assignee.' . $suffix . '@example.test',
            'password' => PasswordHasher::hash('irrelevant'),
            'is_active' => 1,
        ]);

        $task = Task::create([
            'project_id' => $project->id,
            'name' => 'Noten kopieren ' . $suffix,
            'created_by' => $creator->id,
        ]);
        $task->assignees()->attach($assignee->id);

        $creator->delete();

        $kept = Task::find($task->id);

        $this->assertNotNull($kept, 'Die Aufgabe darf mit ihrem Ersteller nicht verschwinden.');
        $this->assertNull($kept->created_by, 'Der Ersteller ist danach unbekannt, nicht ein fremdes Mitglied.');
        $this->assertSame('Noten kopieren ' . $suffix, $kept->name);
        $this->assertSame(
            [(int) $assignee->id],
            $kept->assignees()->pluck('users.id')->map(static fn ($id): int => (int) $id)->all(),
            'Die Zuweisung an ein anderes Mitglied bleibt bestehen.'
        );
    }
}
