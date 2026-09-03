<?php

/**
 * Liest und schreibt den Zählerstand des rotierenden Code-Reviews.
 *
 * Der geplante Review-Lauf braucht die Datei .claude/rotating-review-state.json
 * zweimal: am Anfang, um den heutigen Abschnitt zu bestimmen, und am Ende, um
 * den Zähler weiterzudrehen. Das Fortschreiben lief bisher über das
 * Datei-Werkzeug des Agenten und blieb dort wiederholt an einer Rückfrage
 * hängen - bei einem unbeaufsichtigten Lauf ist das ein Abbruch, und der Zähler
 * bliebe stehen. Über dieses Skript läuft es als gewöhnlicher Befehl.
 *
 * Aufruf:
 *   php bin/rotating_review_state.php read      Zustand als JSON auf die Ausgabe
 *   php bin/rotating_review_state.php section   Abschnitt des anstehenden Laufs
 *   php bin/rotating_review_state.php advance   Lauf verbuchen, Zähler +1
 *
 * `advance` schreibt den gerade gelaufenen Abschnitt nach `lastRun` und erhöht
 * `runNumber`. Der Abschnitt ergibt sich aus `runNumber mod` Anzahl Abschnitte -
 * nicht aus dem Datum, damit ein ausgefallener Tag oder ein zusätzlicher
 * Handlauf die Reihenfolge nicht verschiebt.
 */

declare(strict_types=1);

// Der Pfad ist überschreibbar, damit ein Test den Ablauf gegen eine eigene
// Datei prüfen kann, statt den echten Zählerstand weiterzudrehen.
define('STATE_PATH', getenv('ROTATING_REVIEW_STATE_PATH') ?: __DIR__ . '/../.claude/rotating-review-state.json');

const DEFAULT_SECTIONS = [
    'src/Controllers',
    'src/Services',
    'src/Models',
    'src/Policies',
    'src/Queries',
    'src/Middleware',
    'db/migrations',
];

/**
 * @return array{runNumber:int, lastRun:array<string,mixed>|null, sections:list<string>}
 */
function readState(): array
{
    $sections = DEFAULT_SECTIONS;
    $runNumber = 0;
    $lastRun = null;

    if (is_file(STATE_PATH)) {
        $decoded = json_decode((string) file_get_contents(STATE_PATH), true);
        if (is_array($decoded)) {
            $runNumber = max(0, (int) ($decoded['runNumber'] ?? 0));
            $lastRun = is_array($decoded['lastRun'] ?? null) ? $decoded['lastRun'] : null;

            // Eine geänderte Abschnittsliste gehört in die Datei, nicht hierher:
            // Die Vorgabe oben ist nur der Anfangsbestand.
            $configured = $decoded['sections'] ?? null;
            if (is_array($configured) && $configured !== []) {
                $sections = array_values(array_map('strval', $configured));
            }
        }
    }

    return ['runNumber' => $runNumber, 'lastRun' => $lastRun, 'sections' => $sections];
}

/**
 * @param array{runNumber:int, lastRun:array<string,mixed>|null, sections:list<string>} $state
 */
function writeState(array $state): void
{
    $directory = dirname(STATE_PATH);
    if (!is_dir($directory) && !mkdir($directory, 0755, true) && !is_dir($directory)) {
        fwrite(STDERR, 'Verzeichnis lässt sich nicht anlegen: ' . $directory . PHP_EOL);
        exit(1);
    }

    $encoded = json_encode($state, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    if ($encoded === false) {
        fwrite(STDERR, 'Zustand lässt sich nicht als JSON schreiben.' . PHP_EOL);
        exit(1);
    }

    // Repository-Textdateien tragen LF, siehe instructions/line-endings.md.
    if (file_put_contents(STATE_PATH, $encoded . "\n") === false) {
        fwrite(STDERR, 'Zustand lässt sich nicht schreiben: ' . STATE_PATH . PHP_EOL);
        exit(1);
    }
}

/**
 * @param list<string> $sections
 */
function sectionFor(int $runNumber, array $sections): string
{
    return $sections[$runNumber % count($sections)];
}

$command = $argv[1] ?? 'read';
$state = readState();

switch ($command) {
    case 'read':
        echo json_encode($state, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE), PHP_EOL;
        break;

    case 'section':
        echo sectionFor($state['runNumber'], $state['sections']), PHP_EOL;
        break;

    case 'advance':
        $completed = $state['runNumber'];
        $sectionIndex = $completed % count($state['sections']);

        writeState([
            'runNumber' => $completed + 1,
            'lastRun' => [
                'runNumber' => $completed,
                'sectionIndex' => $sectionIndex,
                'section' => $state['sections'][$sectionIndex],
            ],
            'sections' => $state['sections'],
        ]);

        echo 'Lauf ' . $completed . ' (' . $state['sections'][$sectionIndex] . ') verbucht, '
            . 'nächster Lauf: ' . ($completed + 1) . ' (' . sectionFor($completed + 1, $state['sections']) . ')',
            PHP_EOL;
        break;

    default:
        fwrite(STDERR, 'Unbekannter Befehl: ' . $command . ' (erlaubt: read, section, advance)' . PHP_EOL);
        exit(1);
}
