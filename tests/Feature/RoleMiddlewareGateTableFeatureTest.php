<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Middleware\RoleMiddleware;
use PHPUnit\Framework\TestCase;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\RequestHandlerInterface;
use ReflectionClass;
use ReflectionNamedType;
use Slim\Psr7\Factory\ServerRequestFactory;
use Slim\Psr7\Response;

/**
 * Die RoleMiddleware trug für jedes Rechte-Gate einen eigenen Wahrheitswert im
 * Konstruktor und einen eigenen, fast gleichlautenden `if`-Block. Die Bedeutung
 * hing allein an der Position: Ein Einschub in der Mitte verschob still alle
 * folgenden, weshalb neue Schalter ans Ende gehängt werden mussten - zuletzt
 * sogar hinter den Logger.
 *
 * Jetzt beschreibt eine Tabelle die Gates. Dieser Test hält beide Hälften
 * zusammen: Ein Gate ohne Schalter wäre unerreichbar, ein Schalter ohne Gate
 * wirkungslos - beides fiele sonst niemandem auf.
 */
final class RoleMiddlewareGateTableFeatureTest extends TestCase
{
    /**
     * Konstruktorparameter, die kein Gate schalten.
     */
    private const NON_GATE_PARAMETERS = ['minHierarchyLevel', 'logger'];

    public function testEveryGateHasAMatchingConstructorSwitch(): void
    {
        $missing = array_diff(array_keys($this->gates()), $this->gateParameterNames());

        $this->assertSame(
            [],
            array_values($missing),
            'Gate ohne gleichnamigen Konstruktorparameter - es ließe sich nie einschalten.'
        );
    }

    public function testEveryConstructorSwitchHasAMatchingGate(): void
    {
        $missing = array_diff($this->gateParameterNames(), array_keys($this->gates()));

        $this->assertSame(
            [],
            array_values($missing),
            'Konstruktorparameter ohne Gate-Eintrag - er bliebe wirkungslos.'
        );
    }

    public function testEveryGateIsCompletelyDescribed(): void
    {
        foreach ($this->gates() as $name => $definition) {
            $this->assertNotEmpty($definition['permissions'], "Gate {$name} nennt kein Recht.");
            $this->assertStringStartsWith(
                'Zugriff verweigert:',
                $definition['message'],
                "Gate {$name} meldet die Abweisung nicht als solche."
            );
            $this->assertContains(
                $definition['logged_permission'],
                $definition['permissions'],
                "Gate {$name} protokolliert ein Recht, das es gar nicht prüft."
            );
        }
    }

    public function testCombinedGatesAreEvaluatedInTableOrder(): void
    {
        // Setzt eine Route zwei Gates, entscheidet die Tabellenreihenfolge, welche
        // Meldung ankommt. Genau diese Kombination steht an den Mitglieder-Routen.
        $gateNames = array_keys($this->gates());
        $this->assertLessThan(
            array_search('requiresUserManagement', $gateNames, true),
            array_search('allowVoiceGroupReps', $gateNames, true),
            'allowVoiceGroupReps muss vor requiresUserManagement geprüft werden.'
        );

        $_SESSION = [
            'user_id' => 42,
            'can_manage_users' => false,
            'can_manage_own_voice_group' => false,
        ];

        $middleware = new RoleMiddleware(requiresUserManagement: true, allowVoiceGroupReps: true);
        $response = $middleware->process(
            (new ServerRequestFactory())->createServerRequest('GET', '/users'),
            new class implements RequestHandlerInterface {
                public function handle(ServerRequestInterface $request): ResponseInterface
                {
                    return new Response(200);
                }
            }
        );

        $this->assertSame(403, $response->getStatusCode());
    }

    /**
     * @return array<string, array{permissions: list<string>, logged_permission: string, message: string}>
     */
    private function gates(): array
    {
        $gates = (new ReflectionClass(RoleMiddleware::class))->getConstant('GATES');
        $this->assertIsArray($gates);

        return $gates;
    }

    /**
     * Alle Konstruktorparameter, die ein Gate schalten - also die booleschen.
     *
     * @return list<string>
     */
    private function gateParameterNames(): array
    {
        $constructor = (new ReflectionClass(RoleMiddleware::class))->getConstructor();
        $this->assertNotNull($constructor);

        $names = [];
        foreach ($constructor->getParameters() as $parameter) {
            if (in_array($parameter->getName(), self::NON_GATE_PARAMETERS, true)) {
                continue;
            }

            $type = $parameter->getType();
            if ($type instanceof ReflectionNamedType && $type->getName() === 'bool') {
                $names[] = $parameter->getName();
            }
        }

        return $names;
    }
}
