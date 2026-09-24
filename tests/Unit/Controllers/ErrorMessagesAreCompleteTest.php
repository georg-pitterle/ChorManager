<?php

declare(strict_types=1);

namespace Tests\Unit\Controllers;

use PHPUnit\Framework\TestCase;

/**
 * Wächter gegen eine abgeschnittene Fehlermeldung.
 *
 * `RoleController::delete()` setzte "Datenbankfehler beim Löschen: " - der
 * Doppelpunkt war der Rest, den der Ausbau des Treibertextes hinterließ. Die
 * aufrufende Person las damit eine Meldung, die mitten im Satz endet, und die
 * Ausnahme selbst landete in keinem Protokoll. Dieselbe Stelle in
 * SponsorPackageController trägt den Hinweis darauf schon im Kommentar.
 *
 * Der Test liest nur Dateien, wie Tests\Unit\TestSuite\NoHandBuiltSchemaTest.
 */
final class ErrorMessagesAreCompleteTest extends TestCase
{
    /**
     * @return list<string>
     */
    private function controllerFiles(): array
    {
        $files = glob(dirname(__DIR__, 3) . '/src/Controllers/*.php') ?: [];
        sort($files);

        return $files;
    }

    public function testNoFlashMessageEndsMidSentence(): void
    {
        $offenders = [];

        foreach ($this->controllerFiles() as $path) {
            foreach (file($path) ?: [] as $number => $line) {
                if (!str_contains($line, "\$_SESSION['error']") && !str_contains($line, "\$_SESSION['success']")) {
                    continue;
                }

                // Eine Meldung, die auf ":" oder ": " endet, hat einen Zusatz
                // verloren. Zeilen, die die Meldung erst fortsetzen (Verkettung
                // mit "." am Zeilenende), bleiben außen vor.
                if (preg_match('/=\s*\'[^\']*:\s*\'\s*;\s*$/', rtrim($line)) === 1) {
                    $offenders[] = basename($path) . ':' . ($number + 1) . ' ' . trim($line);
                }
            }
        }

        $this->assertSame(
            [],
            $offenders,
            "Diese Meldungen enden mitten im Satz - der Zusatz dahinter fehlt:\n" . implode("\n", $offenders)
        );
    }

    /**
     * Gegenprobe: Ohne sie bliebe der Wächter auch dann grün, wenn er nach einer
     * Umbenennung des Verzeichnisses gar keine Datei mehr fände.
     */
    public function testTheGuardScansTheControllers(): void
    {
        $this->assertGreaterThan(30, count($this->controllerFiles()));
    }

    public function testTheGuardRecognisesATruncatedMessage(): void
    {
        $this->assertSame(
            1,
            preg_match(
                '/=\s*\'[^\']*:\s*\'\s*;\s*$/',
                "            \$_SESSION['error'] = 'Datenbankfehler beim Löschen: ';"
            )
        );
    }

    /**
     * Die Ausnahme, die das Löschen zu Fall brachte, gehört ins Protokoll - sonst
     * bleibt vom Fehlschlag nur die Meldung an die aufrufende Person.
     */
    public function testRoleDeletionFailureIsLogged(): void
    {
        $source = (string) file_get_contents(dirname(__DIR__, 3) . '/src/Controllers/RoleController.php');

        $this->assertStringContainsString("'event' => 'role.delete.failed'", $source);
        $this->assertMatchesRegularExpression(
            "/'event' => 'role\.delete\.failed',\s*\n\s*'role_id' => \\\$deletedRoleId,\s*\n\s*'exception' => \\\$e,/",
            $source
        );
    }
}
