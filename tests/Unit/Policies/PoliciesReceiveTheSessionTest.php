<?php

declare(strict_types=1);

namespace Tests\Unit\Policies;

use PHPUnit\Framework\TestCase;

/**
 * Jede Policy bekommt die Sitzung von außen, und zwar im Konstruktor.
 *
 * Die Reihe hatte zwei Abweichungen hintereinander. Zuerst lasen drei Policies
 * die Sitzung selbst aus dem Superglobal; das ließ sich im Test nur über das
 * Setzen eines Superglobals steuern. Danach blieb `UserEditPolicy` als
 * einzige, die sie an jeder Methode als ersten Parameter nahm - zwei Wege zur
 * selben Angabe, und beim Lesen musste man erst nachsehen, welcher hier gerade
 * gilt. Als Parameter ließ sich außerdem eine andere Sitzung hineingeben als
 * die der anfragenden Person.
 *
 * Beide Abweichungen sind weg, und beide hält diese Prüfung fest: ein
 * einzelnes `$_SESSION[...]` in einer neuen Methode fällt sonst niemandem auf,
 * und eine sechste Policy mit Sitzungs-Parameter genauso wenig.
 */
class PoliciesReceiveTheSessionTest extends TestCase
{
    public function testKeinePolicyLiestDasSitzungsSuperglobalSelbst(): void
    {
        $offenders = [];

        foreach ($this->policyFiles() as $path) {
            $source = file_get_contents($path);
            $this->assertIsString($source);

            foreach (explode("\n", $source) as $index => $line) {
                if (str_contains($line, '$_SESSION')) {
                    $offenders[] = basename($path) . ':' . ($index + 1) . ' ' . trim($line);
                }
            }
        }

        $this->assertSame(
            [],
            $offenders,
            'Eine Policy holt sich die Sitzung selbst, statt sie übergeben zu bekommen.'
        );
    }

    public function testJedePolicyIstOhneSuperglobalNutzbar(): void
    {
        $session = ['user_id' => 7, 'can_manage_sponsoring' => true];

        // Das Superglobal wird kurz beiseitegelegt und danach zurückgestellt:
        // Ein Prozess führt mehrere Testklassen aus, und eine verschwundene
        // Sitzung wäre für die nächste ein Rätsel ohne Fundort.
        $previous = $_SESSION ?? null;
        $_SESSION = [];

        try {
            $policy = new \App\Policies\SponsoringPolicy($session);

            // Wäre die Sitzung noch aus dem Superglobal gelesen, bliebe beides leer.
            $this->assertTrue($policy->canManageAll());
            $this->assertSame(7, $policy->currentUserId());
        } finally {
            if ($previous === null) {
                unset($_SESSION);
            } else {
                $_SESSION = $previous;
            }
        }
    }

    /**
     * Die Sitzung kommt im Konstruktor, nicht an der Methode.
     *
     * Geprüft über Reflection und nicht über den Quelltext, weil es hier um die
     * Signatur geht: Ein Konstruktor, der die Sitzung nimmt, und keine
     * öffentliche Methode, die sie noch einmal als Parameter annimmt. Nur so
     * kann eine Prüfung gar nicht mehr für jemand anderen antworten als für
     * die Person, für die die Policy gebaut wurde.
     */
    public function testJedePolicyNimmtDieSitzungImKonstruktor(): void
    {
        $withoutSessionConstructor = [];
        $withSessionParameter = [];

        foreach ($this->policyClasses() as $class) {
            $reflection = new \ReflectionClass($class);

            $constructor = $reflection->getConstructor();
            $parameters = $constructor === null ? [] : $constructor->getParameters();
            $takesSession = $parameters !== [] && $parameters[0]->getName() === 'session';

            if (!$takesSession) {
                $withoutSessionConstructor[] = $reflection->getShortName();
            }

            foreach ($reflection->getMethods(\ReflectionMethod::IS_PUBLIC) as $method) {
                if ($method->isConstructor() || $method->getDeclaringClass()->getName() !== $class) {
                    continue;
                }

                foreach ($method->getParameters() as $parameter) {
                    if ($parameter->getName() === 'session') {
                        $withSessionParameter[] = $reflection->getShortName() . '::' . $method->getName();
                    }
                }
            }
        }

        $this->assertSame(
            [],
            $withoutSessionConstructor,
            'Diese Policy nimmt die Sitzung nicht im Konstruktor.'
        );
        $this->assertSame(
            [],
            $withSessionParameter,
            'Diese Methode nimmt die Sitzung noch einmal als Parameter.'
        );
    }

    /**
     * @return list<class-string>
     */
    private function policyClasses(): array
    {
        $classes = [];

        foreach ($this->policyFiles() as $path) {
            /** @var class-string $class */
            $class = 'App\\Policies\\' . basename($path, '.php');
            $this->assertTrue(class_exists($class), $class . ' ist nicht ladbar.');
            $classes[] = $class;
        }

        return $classes;
    }

    /**
     * @return list<string>
     */
    private function policyFiles(): array
    {
        $directory = dirname(__DIR__, 3) . '/src/Policies';
        $files = glob($directory . '/*.php');

        $this->assertIsArray($files);
        $this->assertNotEmpty($files, 'Ohne Dateien prüft diese Klasse nichts.');

        return $files;
    }
}
