<?php

declare(strict_types=1);

namespace Tests\Feature;

use PHPUnit\Framework\TestCase;

/**
 * Der Zählerstand des rotierenden Code-Reviews.
 *
 * Der Abschnitt hängt an der Lauf-Nummer, nicht am Datum: Ein ausgefallener Tag
 * oder ein zusätzlicher Handlauf darf die Reihenfolge nicht verschieben. Und der
 * Zähler muss sich fortschreiben lassen, ohne dass jemand danebensitzt - genau
 * deshalb liegt das Fortschreiben in einem Skript und nicht im Datei-Werkzeug
 * des Agenten, das dabei wiederholt an einer Rückfrage hängen blieb.
 */
final class RotatingReviewStateFeatureTest extends TestCase
{
    private string $statePath;

    protected function setUp(): void
    {
        parent::setUp();
        $this->statePath = sys_get_temp_dir() . '/rotating-review-state-'
            . bin2hex(random_bytes(6)) . '.json';
    }

    protected function tearDown(): void
    {
        if (is_file($this->statePath)) {
            unlink($this->statePath);
        }

        parent::tearDown();
    }

    /**
     * Ohne Datei beginnt die Reihe bei Lauf 0 und damit beim ersten Abschnitt.
     */
    public function testAMissingStateFileStartsAtTheFirstSection(): void
    {
        $this->assertSame('src/Controllers', trim($this->invoke('section')));
    }

    public function testAdvanceBooksTheCompletedRunAndRaisesTheCounter(): void
    {
        $this->writeState(8, ['src/Controllers', 'src/Services', 'src/Models']);

        $this->invoke('advance');
        $state = $this->readState();

        $this->assertSame(9, $state['runNumber']);
        $this->assertSame(8, $state['lastRun']['runNumber']);
        $this->assertSame(2, $state['lastRun']['sectionIndex']);
        $this->assertSame('src/Models', $state['lastRun']['section']);
    }

    /**
     * Sieben Abschnitte, sieben Läufe - danach beginnt die Reihe von vorn, ohne
     * dass irgendwo ein Sonderfall stünde.
     */
    public function testTheRotationWrapsAroundWithoutSkippingASection(): void
    {
        $sections = [
            'src/Controllers',
            'src/Services',
            'src/Models',
            'src/Policies',
            'src/Queries',
            'src/Middleware',
            'db/migrations',
        ];
        $this->writeState(0, $sections);

        $seen = [];
        for ($run = 0; $run < 9; $run++) {
            $seen[] = trim($this->invoke('section'));
            $this->invoke('advance');
        }

        $this->assertSame($sections, array_slice($seen, 0, 7));
        $this->assertSame(['src/Controllers', 'src/Services'], array_slice($seen, 7));
    }

    /**
     * Die Abschnittsliste steht in der Datei. Wird sie dort geändert, richtet
     * sich die Reihe danach - die Vorgabe im Skript ist nur der Anfangsbestand.
     */
    public function testAShortenedSectionListIsHonoured(): void
    {
        $this->writeState(3, ['src/Services', 'db/migrations']);

        // 3 mod 2 = 1 - bei zwei Abschnitten wechselt die Reihe jeden Lauf.
        $this->assertSame('db/migrations', trim($this->invoke('section')));

        $this->writeState(4, ['src/Services', 'db/migrations']);
        $this->assertSame('src/Services', trim($this->invoke('section')));
    }

    /**
     * Die Datei bleibt maschinenlesbar und trägt LF - sie liegt im Repository.
     */
    public function testTheWrittenFileStaysValidJsonWithUnixLineEndings(): void
    {
        $this->writeState(1, ['src/Controllers', 'src/Services']);
        $this->invoke('advance');

        $raw = (string) file_get_contents($this->statePath);

        $this->assertStringNotContainsString("\r", $raw);
        $this->assertStringEndsWith("\n", $raw);
        $this->assertIsArray(json_decode($raw, true));
    }

    public function testAnUnknownCommandFailsLoudly(): void
    {
        $exitCode = 0;
        $this->invoke('kaputt', $exitCode);

        $this->assertSame(1, $exitCode);
    }

    /**
     * @param list<string> $sections
     */
    private function writeState(int $runNumber, array $sections): void
    {
        file_put_contents($this->statePath, (string) json_encode([
            'runNumber' => $runNumber,
            'sections' => $sections,
        ]));
    }

    /**
     * @return array<string, mixed>
     */
    private function readState(): array
    {
        $decoded = json_decode((string) file_get_contents($this->statePath), true);
        $this->assertIsArray($decoded);

        return $decoded;
    }

    private function invoke(string $command, ?int &$exitCode = null): string
    {
        $script = dirname(__DIR__, 2) . '/bin/rotating_review_state.php';
        $invocation = sprintf(
            'ROTATING_REVIEW_STATE_PATH=%s %s %s %s 2>/dev/null',
            escapeshellarg($this->statePath),
            escapeshellarg(PHP_BINARY),
            escapeshellarg($script),
            escapeshellarg($command)
        );

        $output = [];
        $status = 0;
        exec($invocation, $output, $status);
        $exitCode = $status;

        return implode("\n", $output);
    }
}
