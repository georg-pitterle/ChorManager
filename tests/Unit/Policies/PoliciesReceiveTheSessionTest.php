<?php

declare(strict_types=1);

namespace Tests\Unit\Policies;

use PHPUnit\Framework\TestCase;

/**
 * Keine Policy greift selbst in `$_SESSION`.
 *
 * Drei der vier Policies lasen die Sitzung im Konstruktor aus dem Superglobal,
 * `UserEditPolicy` bekam sie als Parameter. Zwei Wege zur selben Angabe: der
 * eine ließ sich im Test nur über das Setzen eines Superglobals steuern, der
 * andere sauber übergeben - und beim Lesen musste man erst nachsehen, welcher
 * Weg hier gerade gilt.
 *
 * Jetzt bekommt jede Policy die Sitzung von außen. Diese Prüfung hält das
 * fest, weil ein einzelnes `$_SESSION[...]` in einer neuen Methode sonst
 * niemandem auffällt.
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
