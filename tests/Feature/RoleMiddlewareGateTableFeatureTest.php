<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Middleware\RoleMiddleware;
use App\Models\Role;
use PHPUnit\Framework\TestCase;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\RequestHandlerInterface;
use InvalidArgumentException;
use ReflectionClass;
use Slim\Psr7\Factory\ServerRequestFactory;
use Slim\Psr7\Response;

/**
 * Die RoleMiddleware trug für jedes Rechte-Gate einen eigenen Wahrheitswert im
 * Konstruktor - zuletzt 22 Stück, jeder Name ein zweites Mal als Schlüssel in
 * einer Zuordnung darunter. Die Route nennt die Gates jetzt beim Namen
 * (`gates: ['requiresTaskManagement']`), und allein die Tabelle self::GATES
 * entscheidet, was es gibt und in welcher Reihenfolge geprüft wird.
 *
 * Damit verschiebt sich auch, worüber dieser Test wachen muss. Die benannten
 * Parameter waren ihr eigener Wächter: Ein Tippfehler war ein
 * `Unknown named parameter` und damit ein Absturz beim Start. Eine Zeichenkette
 * ist das nicht - ein vertippter Name wäre still kein Gate, und die Route liefe
 * ohne jede Rechteprüfung weiter. Deshalb prüft dieser Test vor allem, dass ein
 * unbekannter Name abgewiesen wird.
 */
final class RoleMiddlewareGateTableFeatureTest extends TestCase
{
    /**
     * Die Middleware prüft ausschließlich Rechte, nie das Hierarchie-Level.
     *
     * `minHierarchyLevel` stand hier als Konstruktorparameter samt eigener
     * Abweisung, wurde aber von keiner einzigen Route gesetzt. Das Level
     * entscheidet allein darüber, wessen Zuordnungen jemand ändern darf, und das
     * prüfen UserController und RoleController - nicht diese Middleware. Ein
     * zweiter, halb angeschlossener Weg zur Abweisung ist genau die Art
     * Nebenpfad, die bei der nächsten Rechte-Änderung übersehen wird.
     */
    public function testMiddlewareDoesNotGateOnHierarchyLevel(): void
    {
        $constructor = (new ReflectionClass(RoleMiddleware::class))->getConstructor();
        $this->assertNotNull($constructor);

        $parameterNames = array_map(
            static fn (\ReflectionParameter $parameter): string => $parameter->getName(),
            $constructor->getParameters()
        );

        $this->assertNotContains('minHierarchyLevel', $parameterNames);

        $source = file_get_contents(dirname(__DIR__, 2) . '/src/Middleware/RoleMiddleware.php');
        $this->assertIsString($source);
        $this->assertStringNotContainsString('role_level', $source);
    }

    /**
     * Ein Mitglied ohne Hierarchie-Level kommt durch, solange das Recht sitzt.
     */
    public function testPermissionAloneIsEnoughRegardlessOfHierarchyLevel(): void
    {
        $_SESSION = [
            'user_id' => 42,
            'role_level' => 0,
            'can_manage_finances' => true,
        ];

        $middleware = new RoleMiddleware(gates: ['requiresFinanceManagement']);
        $response = $middleware->process(
            (new ServerRequestFactory())->createServerRequest('GET', '/finances'),
            new class implements RequestHandlerInterface {
                public function handle(ServerRequestInterface $request): ResponseInterface
                {
                    return new Response(200);
                }
            }
        );

        $this->assertSame(200, $response->getStatusCode());
    }

    /**
     * Der Kern der neuen Bauart: Ein Name, den die Tabelle nicht kennt, bricht
     * beim Bauen ab.
     *
     * Ohne diese Ausnahme wäre ein Tippfehler an der Route - `requiresTaskManagment`
     * ohne `e` - still kein Gate. Die Route hinge dann an einer Middleware, die
     * nur noch auf eine Sitzung prüft, und jedes angemeldete Mitglied käme an die
     * Aufgabenverwaltung. Von außen sähe man nur, dass die Seite sich öffnet.
     */
    public function testUnknownGateNameIsRejectedWhileBuilding(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('requiresTaskManagment');

        new RoleMiddleware(gates: ['requiresTaskManagment']);
    }

    /**
     * Auch ein einzelner falscher Name unter richtigen fliegt auf - sonst
     * verdeckte ein gültiges Gate daneben den Fehler.
     */
    public function testOneBadNameAmongValidOnesIsRejected(): void
    {
        $this->expectException(InvalidArgumentException::class);

        new RoleMiddleware(gates: ['requiresUserManagement', 'allowVoiceGroupRepps']);
    }

    /**
     * Die Gegenrichtung: Jeder Name aus der Tabelle wird angenommen. Ein Gate,
     * das sich nicht einschalten lässt, wäre unerreichbar.
     */
    public function testEveryGateNameFromTheTableIsAccepted(): void
    {
        foreach (array_keys($this->gates()) as $gate) {
            $middleware = new RoleMiddleware(gates: [$gate]);
            $this->assertInstanceOf(RoleMiddleware::class, $middleware, "Gate {$gate} wurde abgewiesen.");
        }
    }

    /**
     * Ein eingeschaltetes Gate wirkt auch: ohne das Recht 403, mit dem Recht
     * durch. Geprüft wird das für jedes Gate der Tabelle - ein Eintrag, der
     * zwar angenommen, aber nie geprüft wird, wäre wirkungslos.
     */
    public function testEveryGateActuallyGuardsAndLetsThroughWithItsPermission(): void
    {
        foreach ($this->gates() as $gate => $definition) {
            $_SESSION = ['user_id' => 42];
            $denied = (new RoleMiddleware(gates: [$gate]))->process($this->request(), $this->okHandler());
            $this->assertSame(403, $denied->getStatusCode(), "Gate {$gate} weist ohne Recht nicht ab.");

            foreach ($definition['permissions'] as $permission) {
                $_SESSION = ['user_id' => 42, $permission => true];
                $passed = (new RoleMiddleware(gates: [$gate]))->process($this->request(), $this->okHandler());
                $this->assertSame(
                    200,
                    $passed->getStatusCode(),
                    "Gate {$gate} lässt mit {$permission} nicht durch."
                );
            }
        }
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

    /**
     * Jedes genannte Recht muss es auch geben.
     *
     * Die Middleware liest die Rechte als Sitzungsschlüssel
     * (`$_SESSION['can_manage_finances']`), und SessionAuthService setzt genau
     * die Schlüssel aus `Role::PERMISSIONS`. Ein Tippfehler in der Tabelle - ein
     * fehlendes `s`, ein umbenanntes Recht, dessen Gate stehen blieb - ergibt
     * damit einen Schlüssel, den niemand je gesetzt bekommt: Die Route weist ab
     * sofort *jeden* ab, auch Admins, und das Protokoll nennt als Grund ein
     * Recht, das in der Rollenverwaltung gar nicht auftaucht. Ein Ausfall ohne
     * Fehlermeldung, dem man von außen nicht ansieht, wo er herkommt.
     */
    public function testEveryGatePermissionExistsAsRolePermission(): void
    {
        $known = (new ReflectionClass(Role::class))->getConstant('PERMISSIONS');
        $this->assertIsArray($known);

        foreach ($this->gates() as $name => $definition) {
            foreach ($definition['permissions'] as $permission) {
                $this->assertContains(
                    $permission,
                    $known,
                    "Gate {$name} prüft {$permission} - das Recht steht nicht in Role::PERMISSIONS "
                    . 'und wird deshalb nie in der Sitzung gesetzt.'
                );
            }
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

        $middleware = new RoleMiddleware(gates: ['requiresUserManagement', 'allowVoiceGroupReps']);
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

    private function request(): ServerRequestInterface
    {
        return (new ServerRequestFactory())->createServerRequest('GET', '/irgendeine-route');
    }

    private function okHandler(): RequestHandlerInterface
    {
        return new class implements RequestHandlerInterface {
            public function handle(ServerRequestInterface $request): ResponseInterface
            {
                return new Response(200);
            }
        };
    }
}
