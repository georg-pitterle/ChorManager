<?php

declare(strict_types=1);

namespace Tests\Unit\Policies;

use App\Policies\NewsletterPolicy;
use PHPUnit\Framework\TestCase;

/**
 * Das Recht can_manage_newsletters entscheidet über den ganzen Newsletter-Bereich.
 *
 * Die Policy hielt es als privaten Merker und gab nur canView() heraus. Wer die
 * Verwaltungsfrage selbst brauchte - NewsletterController an elf Stellen, der
 * Dashboard-Controller für die Kachel -, las den Sitzungsschlüssel deshalb noch
 * einmal selbst. Genau davor warnt der Klassenkommentar der Policy: zwei Kopien
 * derselben Zugriffsregel laufen auseinander, sobald eine von beiden angepasst
 * wird. Seit dieser Prüfung gibt es canManage(), und der Sitzungsschlüssel steht
 * nur noch an einer Stelle.
 */
final class NewsletterPolicyTest extends TestCase
{
    public function testOhneRechtVerwaltetNiemandNewsletter(): void
    {
        $this->assertFalse((new NewsletterPolicy([]))->canManage());
        $this->assertFalse((new NewsletterPolicy(['can_manage_newsletters' => 0]))->canManage());
        $this->assertFalse((new NewsletterPolicy(['can_manage_newsletters' => false]))->canManage());
    }

    /**
     * Truthy wie die RoleMiddleware, nicht strikt true: Die Rollenspalte kommt
     * als 1 aus der Datenbank, und ein strikter Vergleich hätte hier still
     * verweigert, während die Middleware dieselbe Sitzung passieren lässt.
     */
    public function testEineEinsAusDerRollenspalteZaehlt(): void
    {
        $this->assertTrue((new NewsletterPolicy(['can_manage_newsletters' => 1]))->canManage());
        $this->assertTrue((new NewsletterPolicy(['can_manage_newsletters' => '1']))->canManage());
        $this->assertTrue((new NewsletterPolicy(['can_manage_newsletters' => true]))->canManage());
    }

    /**
     * Eine Quelle für das Recht, und zwar die Policy.
     *
     * Geprüft über den Quelltext, weil es um die Abwesenheit einer zweiten
     * Quelle geht: Ein einzelnes `$_SESSION['can_manage_newsletters']` in einer
     * neuen Methode fällt sonst niemandem auf, und es ist genau der Weg, auf dem
     * Formular und Speichern im Mitgliederbereich schon einmal auseinanderliefen.
     */
    public function testDasRechtStehtNurInDerPolicy(): void
    {
        $offenders = [];
        $directory = new \RecursiveDirectoryIterator(dirname(__DIR__, 3) . '/src');

        foreach (new \RecursiveIteratorIterator($directory) as $file) {
            if (!$file->isFile() || $file->getExtension() !== 'php') {
                continue;
            }

            $source = file_get_contents($file->getPathname());
            $this->assertIsString($source);

            foreach (explode("\n", $source) as $index => $line) {
                if (preg_match('/\$_SESSION\[[\'"]can_manage_newsletters[\'"]\]/', $line) === 1) {
                    $offenders[] = $file->getFilename() . ':' . ($index + 1) . ' ' . trim($line);
                }
            }
        }

        sort($offenders);

        $this->assertSame(
            [],
            $offenders,
            'Hier wird das Newsletter-Recht noch einmal selbst aus der Sitzung gelesen, '
            . 'statt NewsletterPolicy::canManage() zu fragen.'
        );
    }
}
