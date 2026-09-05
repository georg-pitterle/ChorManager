<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\Project;
use App\Models\User;
use App\Persistence\ProjectPersistence;
use PHPUnit\Framework\TestCase;
use Tests\Unit\Bootstrap;

/**
 * Die Projektzuordnung eines Mitglieds nimmt nur Projekte an, die es gibt.
 *
 * Der Weg dorthin ist ein reiner POST auf /users/{id}: wer nur
 * can_manage_project_members hält, schreibt damit ausschließlich die
 * Zuordnung - und zwar außerhalb des try-Blocks von UserController::update().
 * Eine unbekannte Id lief bis hierher in den Fremdschlüssel von
 * `project_users` und quittierte die Eingabe mit einem HTTP 500 statt mit einer
 * gespeicherten Auswahl. Verworfen wird der unbekannte Wert, nicht die ganze
 * Eingabe: er stammt nicht aus der Oberfläche und darf die erlaubte Zuordnung
 * nicht blockieren.
 */
class ProjectAssignmentUnknownProjectFeatureTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        Bootstrap::setupTestDatabase();
        Bootstrap::getCapsule()?->connection()->beginTransaction();
    }

    protected function tearDown(): void
    {
        $connection = Bootstrap::getCapsule()?->connection();
        if ($connection !== null && $connection->transactionLevel() > 0) {
            $connection->rollBack();
        }

        parent::tearDown();
    }

    public function testUnknownProjectIdsAreDroppedInsteadOfHittingTheForeignKey(): void
    {
        $suffix = bin2hex(random_bytes(4));

        $user = User::create([
            'first_name' => 'Zuordnung',
            'last_name' => 'Testperson',
            'email' => 'projektzuordnung-' . $suffix . '@example.test',
            'password' => password_hash('x', PASSWORD_DEFAULT),
            'is_active' => 1,
        ]);

        $project = Project::create([
            'name' => 'Bestehendes Projekt ' . $suffix,
            'start_date' => date('Y-m-d'),
            'end_date' => date('Y-m-d', strtotime('+1 month')),
        ]);

        $unknownProjectId = ((int) Project::max('id')) + 10000;

        (new ProjectPersistence())->setUserProjects(
            (int) $user->id,
            [(int) $project->id, $unknownProjectId, 0]
        );

        $assigned = $user->projects()->pluck('projects.id')
            ->map(static fn ($id): int => (int) $id)
            ->all();

        $this->assertSame([(int) $project->id], $assigned);
    }
}
