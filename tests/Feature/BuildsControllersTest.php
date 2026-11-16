<?php

declare(strict_types=1);

namespace Tests\Feature;

use InvalidArgumentException;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use ReflectionProperty;
use Slim\Views\Twig;

/**
 * Der Baustein nimmt Abhängigkeiten beim Namen und setzt für Pflichtparameter
 * Vorgaben ein.
 *
 * Ein vertippter Schlüssel darf nie still zur Vorgabe werden: Der Test liefe
 * grün und prüfte etwas anderes als gedacht. Ein ausdrückliches `null` gehört
 * dem Test und wird nicht durch eine Vorgabe ersetzt.
 */
final class BuildsControllersTest extends TestCase
{
    use BuildsControllers;

    public function testAMistypedParameterNameIsRejected(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('loger');

        $this->eventController(['view' => $this->createStub(Twig::class), 'loger' => new NullLogger()]);
    }

    public function testAnExplicitNullIsNotReplacedByADefault(): void
    {
        $controller = $this->eventController([
            'view' => $this->createStub(Twig::class),
            'notificationService' => null,
        ]);

        self::assertNull((new ReflectionProperty($controller, 'notificationService'))->getValue($controller));
    }

    public function testAGivenCollaboratorIsTheOneTheControllerHolds(): void
    {
        $logger = new NullLogger();
        $controller = $this->eventController(['view' => $this->createStub(Twig::class), 'logger' => $logger]);

        self::assertSame($logger, (new ReflectionProperty($controller, 'logger'))->getValue($controller));
    }
}
