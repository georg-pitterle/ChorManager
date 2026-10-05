<?php

declare(strict_types=1);

namespace Tests\Unit\Controllers;

use App\Controllers\HelpController;
use App\Controllers\ProjectController;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use ReflectionClass;
use ReflectionMethod;

/**
 * Antworten auf die Rückfragen aus dem Review-Lauf 35 (src/Controllers).
 *
 * Sechs Entscheidungen, hier festgehalten, soweit sie ohne Datenbank prüfbar
 * sind - reine Rechnung über Reflection, alles andere als Quelltext-Wächter wie
 * in Tests\Unit\TestSuite\NoHandBuiltSchemaTest:
 *
 * 1) Die Rollenverwaltung darf der letzten Rolle das Recht "Mitglieder
 *    verwalten" nicht nehmen (RoleController::wouldOrphanUserManagement).
 * 2) Eine Untergruppe, die nicht zu ihrer Stimmgruppe gehört, wird still
 *    verworfen (UserController::buildVoiceGroupPivot).
 * 3) Die Auswertungen schreiben `users.last_project_id` nur bei einer echten
 *    Änderung (EvaluationController::rememberSelectedProject).
 * 4) Ein Projektende vor dem Projektbeginn wird abgewiesen
 *    (ProjectController::periodIsOrdered).
 * 5) Die Anwesenheitsliste reicht ohne Haken zwölf Monate zurück
 *    (AttendanceController::accessibleEvents).
 * 6) Die Hilfe liefert kein SVG mehr aus (HelpController::IMAGE_MIME_TYPES).
 *
 * Was eine Datenbank braucht - die gefilterte Untergruppe, die verweigerte
 * Rechteentnahme, das Schreiben nur bei Änderung - ist hier als Wächter auf den
 * Quelltext abgebildet und wartet auf einen Feature-Test; der Lauf, der diese
 * Änderung gebracht hat, konnte phpunit nicht starten (PHP 8.3 gegen das
 * verlangte ^8.5).
 */
final class ReviewAnswersRun35Test extends TestCase
{
    private function sourceOf(string $relativePath): string
    {
        $source = file_get_contents(dirname(__DIR__, 3) . '/' . $relativePath);
        $this->assertIsString($source, $relativePath . ' ist nicht lesbar.');

        return $source;
    }

    private static function staticMethod(string $class, string $name): ReflectionMethod
    {
        // Ohne setAccessible(): seit PHP 8.1 ist der Aufruf wirkungslos.
        return new ReflectionMethod($class, $name);
    }

    // 4) Projektzeitraum

    /**
     * @return array<string, array{0: ?string, 1: ?string, 2: bool}>
     */
    public static function projectPeriods(): array
    {
        return [
            'Ende nach Beginn' => ['2026-01-01', '2026-12-31', true],
            'Ende am Beginn' => ['2026-05-04', '2026-05-04', true],
            'Ende vor Beginn' => ['2026-12-31', '2026-01-01', false],
            'Ende einen Tag zu früh' => ['2026-05-04', '2026-05-03', false],
            'ohne Ende' => ['2026-01-01', null, true],
            'ohne Beginn' => [null, '2026-01-01', true],
            'ohne beides' => [null, null, true],
        ];
    }

    #[DataProvider('projectPeriods')]
    public function testProjectPeriodMustNotBeReversed(?string $start, ?string $end, bool $expected): void
    {
        $ordered = self::staticMethod(ProjectController::class, 'periodIsOrdered')->invoke(null, $start, $end);

        $this->assertSame($expected, $ordered);
    }

    public function testBothProjectWritePathsRejectAReversedPeriod(): void
    {
        $source = $this->sourceOf('src/Controllers/ProjectController.php');

        $this->assertSame(
            2,
            substr_count($source, 'if (!self::periodIsOrdered($startDate, $endDate)) {'),
            'Anlegen und Bearbeiten müssen beide prüfen.'
        );
    }

    // Aus demselben Lauf: die Datumsprüfung selbst

    /**
     * @return array<string, array{0: mixed, 1: string|false|null}>
     */
    public static function projectDates(): array
    {
        return [
            'leer' => ['', null],
            'fehlt' => [null, null],
            'gültig' => ['2026-01-31', '2026-01-31'],
            'deutsches Format' => ['31.12.2026', false],
            'Monatsüberlauf' => ['2026-13-45', false],
            'Tagesüberlauf' => ['2026-02-30', false],
            'Unsinn' => ['foo', false],
            'Feld-Array' => [['2026-01-01'], null],
        ];
    }

    #[DataProvider('projectDates')]
    public function testProjectDateIsNormalisedOrRejected(mixed $value, string|false|null $expected): void
    {
        $normalised = self::staticMethod(ProjectController::class, 'normalizeProjectDate')->invoke(null, $value);

        $this->assertSame($expected, $normalised);
    }

    // 6) Hilfe-Bilder

    public function testHelpNeverServesSvg(): void
    {
        $types = (new ReflectionClass(HelpController::class))->getConstant('IMAGE_MIME_TYPES');

        $this->assertIsArray($types);
        $this->assertArrayNotHasKey('svg', $types, 'Ein inline ausgeliefertes SVG führt Skript aus.');
        $this->assertNotContains('image/svg+xml', $types);
        $this->assertArrayHasKey('png', $types, 'Screenshots sind PNG - das muss bleiben.');
    }

    public function testHelpImageRouteDoesNotMatchSvg(): void
    {
        $routes = $this->sourceOf('src/Routes.php');

        $this->assertStringContainsString(
            '/help/images/{file:[A-Za-z0-9_\-\/]+\.(?:png|jpg|jpeg|gif|webp)}',
            $routes,
            'Die Route darf svg nicht mehr annehmen, sonst entscheidet allein der Controller.'
        );
    }

    // 5) Fenster der Anwesenheitsliste

    public function testAttendanceSelectionIsLimitedInTheQuery(): void
    {
        $source = $this->sourceOf('src/Controllers/AttendanceController.php');

        $this->assertStringContainsString('private const RECENT_MONTHS = 12;', $source);
        $this->assertStringContainsString(
            "\$query->where('starts_at', '>=', Carbon::now()->subMonths(self::RECENT_MONTHS));",
            $source,
            'Die Grenze gehört in die Abfrage; nach dem Laden zu filtern spart nichts.'
        );
        $this->assertStringNotContainsString(
            "\$events = Event::where('attendance_required', true)\n            ->with('audienceFilters.conditions')\n"
                . "            ->orderBy('starts_at', 'asc')\n            ->get()",
            $source,
            'Die unbegrenzte Abfrage darf nicht zurückkommen.'
        );
    }

    public function testAttendanceStillReachesAnOlderEventByItsId(): void
    {
        $source = $this->sourceOf('src/Controllers/AttendanceController.php');

        // Ein Lesezeichen auf /attendance/<id> muss aufgehen, auch wenn der Termin
        // aus dem Fenster gewandert ist.
        $this->assertStringContainsString('$requestOutsideWindow', $source);
        $this->assertStringContainsString('$events = $this->accessibleEvents(true);', $source);
    }

    // 1) Rollenverwaltung

    public function testRoleUpdateRefusesToOrphanUserManagement(): void
    {
        $source = $this->sourceOf('src/Controllers/RoleController.php');

        $this->assertStringContainsString(
            'private function wouldOrphanUserManagement(Role $role, array $permissions): bool',
            $source
        );
        $this->assertStringContainsString(
            'if ($this->wouldOrphanUserManagement($existingRole, $permissions)) {',
            $source
        );

        // Erst kappen, dann prüfen: Geprüft werden muss der Stand, der gespeichert würde.
        $capPosition = strpos($source, '$permissions = $this->withoutPermissions($permissions, $cappedPermissions);');
        $guardPosition = strpos($source, 'if ($this->wouldOrphanUserManagement($existingRole, $permissions)) {');

        $this->assertIsInt($capPosition);
        $this->assertIsInt($guardPosition);
        $this->assertLessThan($guardPosition, $capPosition);

        // Und vor dem Schreiben.
        $updatePosition = strpos($source, '$role->update([');
        $this->assertIsInt($updatePosition);
        $this->assertLessThan($updatePosition, $guardPosition);
    }

    public function testRoleGuardCountsOnlyActiveMembers(): void
    {
        $source = $this->sourceOf('src/Controllers/RoleController.php');

        $this->assertStringContainsString(
            "User::where('is_active', 1)",
            $source,
            'Ein archiviertes Konto kann das Recht nicht ausüben.'
        );
        $this->assertStringContainsString("->where('roles.id', '!=', \$exceptRoleId);", $source);

        // Trägt die Rolle kein aktives Mitglied, nimmt das Streichen niemandem etwas.
        $this->assertStringContainsString(
            'if (!$this->roleIsHeldByAnActiveMember((int) $role->id)) {',
            $source
        );
    }

    // 2) Untergruppe und Stimmgruppe

    public function testBothUserWritePathsBuildThePivotThroughTheSharedHelper(): void
    {
        $source = $this->sourceOf('src/Controllers/UserController.php');

        $this->assertSame(
            2,
            substr_count($source, 'self::buildVoiceGroupPivot((array) $voiceGroupIds, (array) $subVoices)'),
            'Anlegen und Bearbeiten müssen denselben Weg nehmen.'
        );
        $this->assertStringNotContainsString(
            "\$svId = !empty(\$subVoices[\$vgId]) ? (int) \$subVoices[\$vgId] : null;",
            $source,
            'Die ungeprüfte Übernahme der Untergruppe darf nicht zurückkommen.'
        );
    }

    public function testPivotHelperChecksTheSubVoiceAgainstItsVoiceGroup(): void
    {
        $source = $this->sourceOf('src/Controllers/UserController.php');

        $this->assertStringContainsString(
            '$belongsToGroup = $subVoiceId !== null && ($groupOfSubVoice[$subVoiceId] ?? null) === $groupId;',
            $source
        );
        $this->assertStringContainsString("->whereIn('voice_group_id', array_keys(\$requested))", $source);
    }

    // 3) Gemerktes Projekt der Auswertungen

    public function testEvaluationsRememberTheProjectOnlyOnChange(): void
    {
        $source = $this->sourceOf('src/Controllers/EvaluationController.php');

        $this->assertSame(
            2,
            substr_count($source, '$this->rememberSelectedProject($userId, $projectId);'),
            'Beide Auswertungsseiten müssen denselben Weg nehmen.'
        );
        $this->assertStringContainsString(
            'if ($user === null || (int) $user->last_project_id === $projectId) {',
            $source,
            'Ohne diesen Vergleich schreibt jeder Seitenaufruf erneut.'
        );
        $this->assertStringNotContainsString(
            "\$user->last_project_id = \$projectId;\n                        \$user->save();",
            $source,
            'Das Schreiben ohne Vergleich darf nicht zurückkommen.'
        );
    }
}
