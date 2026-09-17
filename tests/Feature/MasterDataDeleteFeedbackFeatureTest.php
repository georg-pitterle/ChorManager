<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Controllers\EventTypeController;
use App\Controllers\VoiceGroupController;
use App\Models\EventType;
use PHPUnit\Framework\TestCase;
use Slim\Views\Twig;
use Tests\Unit\Bootstrap;
use Twig\Loader\ArrayLoader;

/**
 * Stammdaten löschen und Terminarten anlegen.
 *
 * Drei Befunde aus dem Review-Lauf über src/Controllers:
 *
 * 1. Scheiterte ein Löschen, stand in der Meldung "Fehler beim Löschen: " - mit
 *    Doppelpunkt und nichts dahinter. Der Grund war irgendwann entfernt worden,
 *    der Doppelpunkt blieb stehen.
 * 2. Beide Controller kannten keinen Logger. Ein gescheitertes Löschen hinterließ
 *    deshalb nirgends eine Spur, entgegen instructions/logging.md.
 * 3. Die Farbe einer Terminart kam ungeprüft aus dem Formular in die Datenbank.
 *    Die Oberfläche bietet sieben Bootstrap-Farben an; ein abweichender Wert
 *    landet als `bg-<unbekannt>` im Abzeichen und färbt es gar nicht mehr.
 */
class MasterDataDeleteFeedbackFeatureTest extends TestCase
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

    private function emptyTwig(): Twig
    {
        // Die geprüften Methoden leiten nur weiter und rendern nichts.
        return new Twig(new ArrayLoader([]));
    }

    public function testDeletingAMissingEventTypeNamesTheReasonAndLogsIt(): void
    {
        [$logger, $handler] = $this->logger();
        $controller = new EventTypeController($this->emptyTwig(), $logger);

        $result = $controller->delete($this->makeRequest('POST', '/event-types/0/delete'), $this->makeResponse(), ['id' => '0']);

        $this->assertRedirect($result, '/event-types');
        $message = (string) ($_SESSION['error'] ?? '');
        $this->assertNotSame('', $message);
        $this->assertStringEndsNotWith(': ', $message);
        $this->assertTrue($this->hasEvent($handler, 'event_type.delete.failed'));
    }

    public function testDeletingAMissingVoiceGroupNamesTheReasonAndLogsIt(): void
    {
        [$logger, $handler] = $this->logger();
        $controller = new VoiceGroupController($this->emptyTwig(), $logger);

        $result = $controller->deleteGroup($this->makeRequest('POST', '/voice-groups/0/delete'), $this->makeResponse(), ['id' => '0']);

        $this->assertRedirect($result, '/voice-groups');
        $message = (string) ($_SESSION['error'] ?? '');
        $this->assertNotSame('', $message);
        $this->assertStringEndsNotWith(': ', $message);
        $this->assertTrue($this->hasEvent($handler, 'voice_group.delete.failed'));
    }

    public function testDeletingAMissingSubVoiceNamesTheReasonAndLogsIt(): void
    {
        [$logger, $handler] = $this->logger();
        $controller = new VoiceGroupController($this->emptyTwig(), $logger);

        $result = $controller->deleteSubVoice(
            $this->makeRequest('POST', '/voice-groups/1/sub/0/delete'),
            $this->makeResponse(),
            ['id' => '1', 'sub_id' => '0']
        );

        $this->assertRedirect($result, '/voice-groups');
        $message = (string) ($_SESSION['error'] ?? '');
        $this->assertNotSame('', $message);
        $this->assertStringEndsNotWith(': ', $message);
        $this->assertTrue($this->hasEvent($handler, 'sub_voice.delete.failed'));
    }

    public function testEventTypeCreateFallsBackToTheDefaultColourForAnUnknownValue(): void
    {
        [$logger] = $this->logger();
        $controller = new EventTypeController($this->emptyTwig(), $logger);

        $name = 'Review-Farbtest ' . bin2hex(random_bytes(4));
        $request = $this->makeRequest('POST', '/event-types', [
            'name' => $name,
            'color' => 'chartreuse" onload="alert(1)',
        ]);
        $result = $controller->create($request, $this->makeResponse());

        $this->assertRedirect($result, '/event-types');

        $created = EventType::where('name', $name)->first();
        $this->assertNotNull($created);
        $this->assertSame('info', (string) $created->color);

        $created->delete();
    }

    public function testEventTypeUpdateFallsBackToTheDefaultColourForAnUnknownValue(): void
    {
        [$logger] = $this->logger();
        $controller = new EventTypeController($this->emptyTwig(), $logger);

        $eventType = EventType::create([
            'name' => 'Review-Farbtest ' . bin2hex(random_bytes(4)),
            'color' => 'success',
        ]);

        $request = $this->makeRequest('POST', '/event-types/' . $eventType->id . '/update', [
            'name' => (string) $eventType->name,
            'color' => 'neongruen',
        ]);
        $result = $controller->update($request, $this->makeResponse(), ['id' => (string) $eventType->id]);

        $this->assertRedirect($result, '/event-types');
        $this->assertSame('info', (string) $eventType->fresh()->color);

        $eventType->delete();
    }

    public function testEventTypeKeepsAnAllowedColour(): void
    {
        [$logger] = $this->logger();
        $controller = new EventTypeController($this->emptyTwig(), $logger);

        $name = 'Review-Farbtest ' . bin2hex(random_bytes(4));
        $request = $this->makeRequest('POST', '/event-types', [
            'name' => $name,
            'color' => 'danger',
        ]);
        $controller->create($request, $this->makeResponse());

        $created = EventType::where('name', $name)->first();
        $this->assertNotNull($created);
        $this->assertSame('danger', (string) $created->color);

        $created->delete();
    }
}
