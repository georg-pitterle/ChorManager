<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Controllers\ProjectSongAssignmentController;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Tests\Unit\Bootstrap;

/**
 * Das Rücksprungziel der Zuordnungs-Formulare kommt aus dem Formularfeld
 * `return_to` und damit von außen.
 *
 * Geprüft wurde nur der Präfix "/song-library". Ein Wert mit CR/LF ging damit
 * unbesehen in `withHeader('Location', ...)`; Slim lehnt einen solchen Kopfwert
 * mit einer InvalidArgumentException ab, und aus dem Speichern wurde eine
 * Fehlerseite statt eines Rücksprungs. Seitdem läuft der Wert erst durch
 * SafeRedirect - dieselbe Bauart wie bei EventController.
 */
final class ProjectSongAssignmentReturnToFeatureTest extends TestCase
{
    use TestHttpHelpers;

    protected function setUp(): void
    {
        parent::setUp();
        Bootstrap::setupTestDatabase();
        $_SESSION = [];
    }

    protected function tearDown(): void
    {
        $_SESSION = [];
        parent::tearDown();
    }

    /**
     * @return array<string, array{0: mixed, 1: string}>
     */
    public static function rejectedTargets(): array
    {
        return [
            'Zeilenumbruch im Ziel' => ["/song-library\r\nX-Injected: 1", '/song-library'],
            'fremder Gastgeber' => ['https://example.invalid/song-library', '/song-library'],
            'schemaloses fremdes Ziel' => ['//example.invalid/song-library', '/song-library'],
            'umgekehrter Schrägstrich' => ['/song-library\\..\\admin', '/song-library'],
            'fremder Pfad' => ['/settings', '/song-library'],
            'Feld-Array' => [['/song-library'], '/song-library'],
            'kein Wert' => [null, '/song-library'],
        ];
    }

    #[DataProvider('rejectedTargets')]
    public function testAnUnsafeReturnTargetFallsBackToTheLibrary(mixed $candidate, string $expected): void
    {
        // Ohne Lied-Id fällt der Rücksprung auf die Übersicht zurück; die
        // Zuordnung selbst scheitert vorher an der fehlenden song_id, und genau
        // der Fehlerweg setzt den Kopf, um den es hier geht.
        $response = (new ProjectSongAssignmentController())->create(
            $this->makeRequest('POST', '/song-library/assignments', ['return_to' => $candidate]),
            $this->makeResponse()
        );

        $this->assertRedirect($response, $expected);
    }

    public function testAnOwnTargetIsKept(): void
    {
        $response = (new ProjectSongAssignmentController())->create(
            $this->makeRequest('POST', '/song-library/assignments', [
                'return_to' => '/song-library/7?tab=projects',
            ]),
            $this->makeResponse()
        );

        $this->assertRedirect($response, '/song-library/7?tab=projects');
    }
}
