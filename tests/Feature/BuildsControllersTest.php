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

    /**
     * Geprüft an einer eigenen Klasse und nicht an einem Controller: Seit der
     * EventController den Benachrichtigungsdienst nicht mehr selbst hält
     * (17.11.2026), steht keine Vorgabe des Bausteins mehr auf einem Parameter,
     * der `null` annimmt - und genau diese Paarung ist hier die Frage.
     */
    public function testAnExplicitNullIsNotReplacedByADefault(): void
    {
        $built = $this->buildController(OptionalCollaboratorFixture::class, ['collaborator' => null], [
            'collaborator' => static fn (): NullLogger => new NullLogger(),
        ]);

        self::assertNull($built->collaborator);
    }

    public function testAGivenCollaboratorIsTheOneTheControllerHolds(): void
    {
        $logger = new NullLogger();
        $controller = $this->eventController(['view' => $this->createStub(Twig::class), 'logger' => $logger]);

        self::assertSame($logger, (new ReflectionProperty($controller, 'logger'))->getValue($controller));
    }
}

/**
 * Eine Klasse mit genau dem, was der Baustein prüfbar machen soll: ein
 * Mitarbeiter, der fehlen darf.
 */
final class OptionalCollaboratorFixture
{
    public function __construct(public readonly ?NullLogger $collaborator = null)
    {
    }
}
