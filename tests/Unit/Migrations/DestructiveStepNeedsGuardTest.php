<?php

declare(strict_types=1);

namespace Tests\Unit\Migrations;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * `instructions/database.md` verlangt vor jedem Schritt, der Daten beseitigt,
 * eine Vollständigkeitsprüfung, die mit `RuntimeException` abbricht - und zwar
 * **vor** dem Schritt, nicht danach. Danach sind die Daten fort, und nachholen
 * lässt sich das nicht, weil Phinx den Lauf bereits in `phinxlog` verbucht hat.
 *
 * Geprüft hat das bisher `DestructiveMigrationGuardTest`, aber nur für vier
 * handverlesene Fälle: Es lässt die Zählabfragen dieser vier gegen
 * Wegwerf-Tabellen laufen und stellt fest, dass sie die betroffene Zeile
 * wirklich finden. Das ist die schärfere Prüfung, sie kostet aber je Migration
 * eine eigene Konstante und ein eigenes Szenario. Eine neue Migration ohne
 * Prüfung fiel deshalb durch beide Netze.
 *
 * Dieser Test zieht die Regel für alle Migrationen nach, dafür nur statisch: Er
 * fragt nicht, ob eine Prüfung das Richtige zählt, sondern ob überhaupt eine da
 * ist und vor dem destruktiven Schritt steht.
 *
 * ## Was als destruktiv gilt
 *
 * Eine Spalte verlieren und eine Tabelle verlieren - aber nur, wenn die
 * Migration sie nicht selbst angelegt hat. Ein `drop()` im `down()` auf eine
 * Tabelle, die das `up()` erzeugt hat, nimmt eine Erzeugung zurück und
 * vernichtet nichts, was vorher da war; dasselbe gilt für `removeColumn()` auf
 * eine Spalte aus dem eigenen `addColumn()`. Solche Umkehrungen erkennt der
 * Test und lässt sie durch.
 *
 * ## Warum MODIFY ... NOT NULL fehlt
 *
 * `instructions/database.md` nennt es in einem Atemzug mit den beiden anderen,
 * und zu Recht: Eine Spalte enger zu machen wirft die Zeilen weg, die nicht mehr
 * hineinpassen. Statisch ist der Fall aber nicht von seinem harmlosen Zwilling
 * zu trennen. `MODIFY COLUMN status enum(...) NOT NULL` schreibt in
 * 20260419223000 und 20260420112000 bloß die Nullbarkeit mit, die die Spalte
 * ohnehin schon hatte - der Wortlaut ist derselbe wie bei einer echten
 * Verengung, die Wirkung eine völlig andere, und welche von beiden vorliegt,
 * steht nur im Schema davor. Ein Test, der beides gleich behandelt, erzwingt
 * tote Prüfungen und wird abgeschaltet statt befolgt. Die echten Verengungen
 * (20260820120000, 20260811190000, 20260825120300, 20260826120000) haben ihre
 * Prüfung und werden von `DestructiveMigrationGuardTest` bewacht.
 */
final class DestructiveStepNeedsGuardTest extends TestCase
{
    private const MIGRATION_DIR = __DIR__ . '/../../../db/migrations';

    /**
     * Zwei Migrationen aus der Frühzeit kommen ohne Prüfung aus, weil die
     * Umschreibung unmittelbar davor bedingungslos läuft und aus einer Quelle
     * liest, die keine Lücke haben kann. Eine Prüfung wäre dort nachweislich
     * toter Code - sie könnte nie anschlagen.
     *
     * Die Liste ist bewusst kurz und soll es bleiben: Eine neue Migration gehört
     * nicht hier hinein, sondern bekommt ihre Prüfung.
     *
     * @var array<string, string>
     */
    private const DOCUMENTED_EXCEPTIONS = [
        '20260424100000_add_starts_at_ends_at_to_events.php' =>
            'Der Backfill davor setzt starts_at/ends_at für jede Zeile aus event_date, '
                . 'und event_date war NOT NULL - es kann keine Zeile ohne Wert geben, '
                . 'wenn DROP COLUMN event_date an die Reihe kommt.',
        '20260513220000_add_newsletter_recipient_sources.php' =>
            'Das INSERT ... SELECT davor überträgt jedes newsletters.event_id IS NOT NULL '
                . 'ohne weitere Bedingung nach newsletter_recipient_sources, bevor '
                . 'removeColumn(event_id) folgt.',
    ];

    /**
     * @return array<string, array{0: string}>
     */
    public static function migrationFileProvider(): array
    {
        $files = glob(self::MIGRATION_DIR . '/*.php');
        self::assertNotFalse($files, 'Migrationsverzeichnis nicht lesbar.');
        self::assertNotSame([], $files, 'Keine Migrationen gefunden.');

        $cases = [];
        foreach ($files as $file) {
            $cases[basename($file)] = [$file];
        }

        return $cases;
    }

    #[DataProvider('migrationFileProvider')]
    public function testDestructiveStepIsPrecededByAGuard(string $file): void
    {
        $raw = file_get_contents($file);
        self::assertIsString($raw, 'Migration nicht lesbar: ' . $file);

        $source = $this->withoutComments($raw);
        $bodies = $this->methodBodies($file, $source);

        // Die Hinrichtung der Migration: up(), oder change() bei den reversiblen.
        $forward = $bodies['up'] ?? $bodies['change'] ?? '';
        $forwardTables = $this->tablesCreatedIn($forward);
        $forwardColumns = $this->columnsAddedIn($forward);

        $unguarded = [];
        foreach ($bodies as $method => $body) {
            $isForward = $method !== 'down';
            $tables = $this->tablesCreatedIn($body);
            $columns = $this->columnsAddedIn($body);

            foreach ($this->destructiveSteps($body) as $offset => $step) {
                [$kind, $target] = $step;

                // Im eigenen Rumpf zählt nur, was vor dem Schritt angelegt wurde.
                $ownHere = $kind === 'table' ? $tables : $columns;
                if (($ownHere[$target] ?? PHP_INT_MAX) < $offset) {
                    continue;
                }

                // Ein down(), das eine Erzeugung des up() zurücknimmt, vernichtet
                // nichts, was vor der Migration da war.
                $ownForward = $kind === 'table' ? $forwardTables : $forwardColumns;
                if (!$isForward && isset($ownForward[$target])) {
                    continue;
                }

                if ($this->hasGuardBefore($body, $offset)) {
                    continue;
                }

                $unguarded[] = sprintf('%s(): %s %s', $method, $kind, $target);
            }
        }

        if (isset(self::DOCUMENTED_EXCEPTIONS[basename($file)])) {
            $this->assertNotSame(
                [],
                $unguarded,
                sprintf(
                    '%s steht als Ausnahme in DOCUMENTED_EXCEPTIONS, hat aber inzwischen eine '
                        . 'Prüfung oder keinen destruktiven Schritt mehr. Eintrag entfernen.',
                    basename($file)
                )
            );

            return;
        }

        $this->assertSame(
            [],
            $unguarded,
            sprintf(
                "%s: destruktiver Schritt ohne vorangehende Prüfung.\n%s\n"
                    . 'Vor jedem DROP COLUMN / DROP TABLE auf Bestand gehört eine Zählabfrage, '
                    . 'die mit RuntimeException abbricht - und zwar davor, nicht danach. '
                    . 'Muster: 20260421120000_drop_songs_project_id. Siehe instructions/database.md.',
                basename($file),
                implode("\n", $unguarded)
            )
        );
    }

    public function testEveryDocumentedExceptionStillExists(): void
    {
        foreach (array_keys(self::DOCUMENTED_EXCEPTIONS) as $name) {
            $this->assertFileExists(
                self::MIGRATION_DIR . '/' . $name,
                'Ausnahme nennt eine Migration, die es nicht mehr gibt: ' . $name
            );
        }
    }

    /**
     * Ersetzt jeden Kommentar durch Leerzeichen gleicher Länge. Zeilennummern
     * und Zeichenpositionen bleiben damit gültig, der Inhalt verschwindet.
     *
     * Ohne diesen Schritt liest der Test die deutsche Prosa mit: In
     * 20260621120000 steht "nach dem DROP COLUMN ersatzlos weg", und das Muster
     * für DROP COLUMN fände dort die Spalte "ersatzlos".
     */
    private function withoutComments(string $source): string
    {
        $out = '';
        foreach (token_get_all($source) as $token) {
            if (!is_array($token)) {
                $out .= $token;
                continue;
            }

            if ($token[0] === T_COMMENT || $token[0] === T_DOC_COMMENT) {
                $out .= preg_replace('/[^\r\n]/', ' ', $token[1]);
                continue;
            }

            $out .= $token[1];
        }

        return $out;
    }

    /**
     * Die Rümpfe von up(), down() und change(), je über die Zeilen aus der
     * Reflection geschnitten. Klammern zu zählen scheitert an Ausdrücken wie
     * "{$assignments}" in 20260731090100.
     *
     * @return array<string, string>
     */
    private function methodBodies(string $file, string $source): array
    {
        require_once $file;

        $class = $this->classNameFor($file);
        self::assertTrue(class_exists($class), 'Klasse nicht gefunden: ' . $class);

        $lines = preg_split('/(?<=\n)/', $source, -1, PREG_SPLIT_NO_EMPTY);
        self::assertIsArray($lines, 'Migration nicht zeilenweise lesbar: ' . $file);

        $bodies = [];
        foreach (['up', 'down', 'change'] as $method) {
            if (!method_exists($class, $method)) {
                continue;
            }

            $reflection = new \ReflectionMethod($class, $method);
            if ($reflection->getDeclaringClass()->getName() !== $class) {
                continue;
            }

            $start = $reflection->getStartLine() - 1;
            $bodies[$method] = implode('', array_slice($lines, $start, $reflection->getEndLine() - $start));
        }

        return $bodies;
    }

    /**
     * Phinx leitet den Klassennamen genauso aus dem Dateinamen ab.
     */
    private function classNameFor(string $file): string
    {
        $name = preg_replace('/^\d+_/', '', basename($file, '.php')) ?? '';

        return str_replace(' ', '', ucwords(str_replace('_', ' ', $name)));
    }

    /**
     * Destruktive Schritte mit ihrer Position im Rumpf.
     *
     * @return array<int, array{0: string, 1: string}>
     */
    private function destructiveSteps(string $body): array
    {
        $steps = [];

        $patterns = [
            'column' => [
                '/removeColumn\(\s*[\'"](\w+)[\'"]/',
                '/DROP\s+COLUMN\s+`?(\w+)`?/i',
            ],
            'table' => [
                '/\$this->table\(\s*[\'"](\w+)[\'"]\s*\)\s*(?:\r?\n\s*)?->drop\(\)/',
                '/DROP\s+TABLE\s+(?:IF\s+EXISTS\s+)?`?(\w+)`?/i',
            ],
        ];

        foreach ($patterns as $kind => $expressions) {
            foreach ($expressions as $expression) {
                if (preg_match_all($expression, $body, $matches, PREG_OFFSET_CAPTURE) === false) {
                    continue;
                }

                foreach ($matches[1] as $match) {
                    $steps[(int) $match[1]] = [$kind, (string) $match[0]];
                }
            }
        }

        ksort($steps);

        return $steps;
    }

    /**
     * Tabellen, die dieser Rumpf selbst anlegt, je mit der Position ihrer ersten
     * Erzeugung.
     *
     * @return array<string, int>
     */
    private function tablesCreatedIn(string $source): array
    {
        $tables = [];

        $this->collect('/CREATE\s+TABLE\s+(?:IF\s+NOT\s+EXISTS\s+)?`?(\w+)`?/i', $source, $tables);

        // $this->table('name', …) … ->create()  - die Kette darf über Zeilen laufen.
        $this->collect('/\$this->table\(\s*[\'"](\w+)[\'"][^;]*?->create\(\)/s', $source, $tables);

        // Die zweite Schreibweise: erst in eine Variable, dann darauf create().
        // 20260530120000 macht es so.
        preg_match_all(
            '/\$(\w+)\s*=\s*\$this->table\(\s*[\'"](\w+)[\'"]/',
            $source,
            $assigned,
            PREG_SET_ORDER | PREG_OFFSET_CAPTURE
        );
        foreach ($assigned as $match) {
            [$variable] = $match[1];
            [$name, $offset] = $match[2];
            if (preg_match('/\$' . preg_quote($variable, '/') . '\b[^;]*->create\(\)/s', $source) !== 1) {
                continue;
            }

            $tables[$name] = min($tables[$name] ?? PHP_INT_MAX, (int) $offset);
        }

        return $tables;
    }

    /**
     * @return array<string, int>
     */
    private function columnsAddedIn(string $source): array
    {
        $columns = [];

        $this->collect('/addColumn\(\s*[\'"](\w+)[\'"]/', $source, $columns);
        $this->collect('/ADD\s+COLUMN\s+`?(\w+)`?/i', $source, $columns);

        return $columns;
    }

    /**
     * @param array<string, int> $into
     */
    private function collect(string $expression, string $source, array &$into): void
    {
        if (preg_match_all($expression, $source, $matches, PREG_OFFSET_CAPTURE) === false) {
            return;
        }

        foreach ($matches[1] as [$name, $offset]) {
            $into[(string) $name] = min($into[(string) $name] ?? PHP_INT_MAX, (int) $offset);
        }
    }

    private function hasGuardBefore(string $body, int $offset): bool
    {
        $before = substr($body, 0, $offset);

        return preg_match('/throw\s+new\s+\\\\?RuntimeException/', $before) === 1;
    }
}
