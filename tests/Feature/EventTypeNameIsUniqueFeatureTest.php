<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Controllers\EventTypeController;
use App\Models\EventType;
use Illuminate\Database\Capsule\Manager as Capsule;
use PHPUnit\Framework\TestCase;
use Slim\Views\Twig;
use Tests\Unit\Bootstrap;

/**
 * Zwei Terminarten tragen nie denselben Namen.
 *
 * `event_types.name` war ohne eindeutigen Index, und das Formular prüfte
 * nichts: Zwei Terminarten "Probe" waren anlegbar und im Termin-Formular nicht
 * zu unterscheiden - dort steht von der Terminart nur der Name. Alle übrigen
 * Stammdaten mit Namen halten die Eindeutigkeit längst. Gefunden im
 * Review-Lauf 83 (db/migrations), so entschieden in der Nacharbeit.
 *
 * Die Kollation `utf8mb4_unicode_ci` unterscheidet Groß- und Kleinschreibung
 * nicht: "Probe" und "probe" gelten als derselbe Name.
 */
final class EventTypeNameIsUniqueFeatureTest extends TestCase
{
    use TestHttpHelpers;

    protected function setUp(): void
    {
        parent::setUp();
        Bootstrap::setupTestDatabase();
        Capsule::connection()->beginTransaction();
        $_SESSION = [];
    }

    protected function tearDown(): void
    {
        $connection = Capsule::connection();
        if ($connection->transactionLevel() > 0) {
            $connection->rollBack();
        }

        $_SESSION = [];
        parent::tearDown();
    }

    private function controller(): EventTypeController
    {
        return new EventTypeController($this->createStub(Twig::class));
    }

    private function postEventType(string $name): void
    {
        $this->controller()->create(
            $this->makeRequest('POST', '/event-types', ['name' => $name, 'color' => 'info']),
            $this->makeResponse()
        );
    }

    public function testCreateRefusesANameAnotherEventTypeAlreadyCarries(): void
    {
        $name = 'Probe ' . bin2hex(random_bytes(4));

        $this->postEventType($name);
        $this->postEventType($name);

        $this->assertSame(1, EventType::where('name', $name)->count());
        $this->assertSame(
            'Eine Terminart mit diesem Namen existiert bereits.',
            $_SESSION['error'] ?? null
        );
    }

    public function testCreateTreatsADifferentCapitalisationAsTheSameName(): void
    {
        $suffix = bin2hex(random_bytes(4));

        $this->postEventType('Generalprobe ' . $suffix);
        $this->postEventType('generalprobe ' . $suffix);

        $this->assertSame(1, EventType::where('name', 'like', '%' . $suffix)->count());
    }

    public function testUpdateRefusesTheNameOfAnotherEventType(): void
    {
        $suffix = bin2hex(random_bytes(4));
        $taken = 'Konzert ' . $suffix;
        $own = 'Auftritt ' . $suffix;

        $this->postEventType($taken);
        $this->postEventType($own);

        $eventType = EventType::where('name', $own)->firstOrFail();

        $this->controller()->update(
            $this->makeRequest('POST', '/event-types/' . $eventType->id, [
                'name' => $taken,
                'color' => 'info',
            ]),
            $this->makeResponse(),
            ['id' => (string) $eventType->id]
        );

        $this->assertSame($own, EventType::findOrFail($eventType->id)->name);
        $this->assertSame(
            'Eine andere Terminart mit diesem Namen existiert bereits.',
            $_SESSION['error'] ?? null
        );
    }
}
