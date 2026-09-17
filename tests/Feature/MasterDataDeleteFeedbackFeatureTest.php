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
 *    der Doppelpunkt blieb stehen. Der mit Abstand häufigste Anlass ist eine
 *    offen liegende Seite, auf der die Zeile inzwischen weg ist; die Meldung
 *    sagt das jetzt und erfindet keine Ursache dazu.
 * 2. Beide Controller kannten keinen Logger. Ein echter Schreibfehler hinterließ
 *    deshalb nirgends eine Spur, entgegen instructions/logging.md. Die fehlende
 *    Zeile ist kein solcher Fehler und bleibt bewusst ohne Protokolleintrag.
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

    /**
     * @return array{0: \Psr\Http\Message\ResponseInterface, 1: string}
     */
    private function deleteAndReadMessage(callable $call): array
    {
        $_SESSION = [];
        $response = $call();

        return [$response, (string) ($_SESSION['error'] ?? '')];
    }

    public function testDeletingAMissingEventTypeNamesTheReason(): void
    {
        [$logger, $handler] = $this->logger();
        $controller = new EventTypeController($this->emptyTwig(), $logger);

        [$result, $message] = $this->deleteAndReadMessage(fn() => $controller->delete(
            $this->makeRequest('POST', '/event-types/0/delete'),
            $this->makeResponse(),
            ['id' => '0']
        ));

        $this->assertRedirect($result, '/event-types');
        $this->assertNotSame('', $message);
        $this->assertStringEndsNotWith(': ', $message);
        $this->assertStringContainsString('bereits gelöscht', $message);

        // Eine fehlende Zeile ist ein Wettlauf zweier offener Seiten, kein
        // Betriebsfehler - sie gehört nicht als error ins Protokoll.
        $this->assertFalse($this->hasEvent($handler, 'event_type.delete.failed'));
    }

    public function testDeletingAMissingVoiceGroupNamesTheReason(): void
    {
        [$logger, $handler] = $this->logger();
        $controller = new VoiceGroupController($this->emptyTwig(), $logger);

        [$result, $message] = $this->deleteAndReadMessage(fn() => $controller->deleteGroup(
            $this->makeRequest('POST', '/voice-groups/0/delete'),
            $this->makeResponse(),
            ['id' => '0']
        ));

        $this->assertRedirect($result, '/voice-groups');
        $this->assertNotSame('', $message);
        $this->assertStringEndsNotWith(': ', $message);
        $this->assertStringContainsString('bereits gelöscht', $message);
        $this->assertFalse($this->hasEvent($handler, 'voice_group.delete.failed'));
    }

    public function testDeletingAMissingSubVoiceNamesTheReason(): void
    {
        [$logger, $handler] = $this->logger();
        $controller = new VoiceGroupController($this->emptyTwig(), $logger);

        [$result, $message] = $this->deleteAndReadMessage(fn() => $controller->deleteSubVoice(
            $this->makeRequest('POST', '/voice-groups/1/sub/0/delete'),
            $this->makeResponse(),
            ['id' => '1', 'sub_id' => '0']
        ));

        $this->assertRedirect($result, '/voice-groups');
        $this->assertNotSame('', $message);
        $this->assertStringEndsNotWith(': ', $message);
        $this->assertStringContainsString('bereits gelöscht', $message);
        $this->assertFalse($this->hasEvent($handler, 'sub_voice.delete.failed'));
    }

    /**
     * Ein echter Schreibfehler, nicht nachgestellt: `event_types.name` ist
     * varchar(255) und die Datenbank läuft mit STRICT_TRANS_TABLES, ein
     * längerer Name endet also in einer QueryException. Genau die muss das
     * Protokoll erreichen - und der Treibertext darf den Bildschirm nicht.
     */
    public function testAFailedEventTypeWriteReachesTheLogWithoutLeakingTheDriverText(): void
    {
        [$logger, $handler] = $this->logger();
        $controller = new EventTypeController($this->emptyTwig(), $logger);

        $request = $this->makeRequest('POST', '/event-types', [
            'name' => str_repeat('x', 300),
            'color' => 'info',
        ]);
        $result = $controller->create($request, $this->makeResponse());

        $this->assertRedirect($result, '/event-types');

        $record = $this->recordFor($handler, 'event_type.create.failed');
        $this->assertNotNull($record);

        // Der Text steht unter `{scope}_message`; `{scope}_error` traegt nur die
        // Kennung des Modals und haette die Pruefung leerlaufen lassen.
        $shown = (string) ($_SESSION['event_type_create_message'] ?? '');
        $this->assertNotSame('', $shown);
        $this->assertStringNotContainsString('SQLSTATE', $shown);
        $this->assertSame($shown, (string) ($_SESSION['error'] ?? ''));
    }

    public function testAFailedVoiceGroupWriteReachesTheLog(): void
    {
        [$logger, $handler] = $this->logger();
        $controller = new VoiceGroupController($this->emptyTwig(), $logger);

        $request = $this->makeRequest('POST', '/voice-groups', ['name' => str_repeat('x', 300)]);
        $result = $controller->createGroup($request, $this->makeResponse());

        $this->assertRedirect($result, '/voice-groups');
        $this->assertNotNull($this->recordFor($handler, 'voice_group.create.failed'));
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
