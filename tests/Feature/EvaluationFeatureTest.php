<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Controllers\EvaluationController;
use PHPUnit\Framework\TestCase;

class EvaluationFeatureTest extends TestCase
{
    public function testEvaluationStructureExists(): void
    {
        $this->assertTrue(class_exists(EvaluationController::class));
        $this->assertTrue(method_exists(EvaluationController::class, 'index'));
        $this->assertTrue(method_exists(EvaluationController::class, 'projectMembers'));

        $controllerContent = file_get_contents(dirname(__DIR__) . '/../src/Controllers/EvaluationController.php');
        $this->assertIsString($controllerContent);
        // Sortiert und gesucht wird im Browser (Tabellen-Engine); serverseitige
        // Tabellenparameter wurden gebaut, aber nie gelesen.
        $this->assertStringNotContainsString('TableQueryParams::from', $controllerContent);
        // Die Projektauswahl liegt seit der Zusammenlegung in ProjectQuery - dort teilen
        // Termine und Auswertungen dieselbe Abfrage, statt je eine Kopie zu halten.
        $this->assertStringContainsString(
            '$this->projectQuery->getAccessibleProjects($userId, $this->canSeeAllProjects())',
            $controllerContent
        );
        $queryContent = file_get_contents(dirname(__DIR__) . '/../src/Queries/ProjectQuery.php');
        $this->assertIsString($queryContent);
        $this->assertStringContainsString(
            'public function getAccessibleProjects(int $userId, bool $seesAllProjects)',
            $queryContent
        );
        $this->assertStringContainsString('if (!in_array($projectId, $accessibleProjectIds, true)) {', $controllerContent);

        // Die Besetzung der Auswertung kommt seit der Antwort auf Lauf 74 ebenfalls
        // aus ProjectQuery. Vorher stand die Abfrage als `User::where('is_active', 1)`
        // im Controller und lud die ganze Zeile samt Passwort-Hash; dieser Wächter
        // hielt genau diese Kopie fest und wandert deshalb mit ihr.
        $this->assertStringContainsString(
            '$this->projectQuery->getMembersWithAttendances($projectId, $onlyUserId)',
            $controllerContent
        );
        $this->assertStringContainsString(
            'public function getMembersWithAttendances(int $projectId, ?int $onlyUserId = null)',
            $queryContent
        );
        $this->assertStringContainsString(
            'User::select(User::LIST_COLUMNS)',
            $queryContent,
            'Die Besetzung der Auswertung darf den Passwort-Hash nicht laden.'
        );

        $routesContent = file_get_contents(dirname(__DIR__) . '/../src/Routes.php');
        $this->assertIsString($routesContent);
        $this->assertStringContainsString("'/evaluations'", $routesContent);
        $this->assertStringContainsString("'/evaluations/project-members'", $routesContent);

        $this->assertTrue(file_exists(dirname(__DIR__) . '/../templates/evaluations/index.twig'));
        $this->assertTrue(file_exists(dirname(__DIR__) . '/../templates/evaluations/project_members.twig'));
    }
}
