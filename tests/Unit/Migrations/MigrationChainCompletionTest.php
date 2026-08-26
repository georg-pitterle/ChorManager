<?php

declare(strict_types=1);

namespace Tests\Unit\Migrations;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * Phinx sammelt `addColumn()`, `removeColumn()`, `drop()` und Konsorten nur in
 * einer Aktionsliste; ausgeführt wird sie erst durch `create()`, `save()` oder
 * `update()`. Fehlt der Abschluss, meldet `phinx migrate`/`rollback` trotzdem
 * Erfolg - die Änderung findet aber nie statt, und der Fehler fällt erst beim
 * nächsten Lauf auf ("Tabelle existiert bereits").
 *
 * Genau das war in 20260530120000_create_calendar_subscription_tokens der Fall.
 * Der Test liest die Migrationen statisch und braucht deshalb keine Datenbank.
 */
final class MigrationChainCompletionTest extends TestCase
{
    private const MIGRATION_DIR = __DIR__ . '/../../../db/migrations';

    /**
     * Aktionen, die ohne Abschluss folgenlos bleiben.
     */
    private const PENDING_ACTIONS = [
        'addColumn',
        'changeColumn',
        'removeColumn',
        'renameColumn',
        'addIndex',
        'removeIndex',
        'removeIndexByName',
        'addForeignKey',
        'dropForeignKey',
        'drop',
        'rename',
    ];

    private const TERMINATORS = ['create', 'save', 'update'];

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
    public function testTableChainsAreCommitted(string $file): void
    {
        $unfinished = [];

        foreach ($this->tableStatements($file) as $line => $statement) {
            if ($this->hasPendingAction($statement) && !$this->isCommitted($statement)) {
                $unfinished[] = sprintf('Zeile %d: %s', $line, $this->condense($statement));
            }
        }

        $this->assertSame(
            [],
            $unfinished,
            sprintf(
                "%s: Tabellen-Aufruf ohne create()/save()/update() - die Aktion wird nie ausgeführt:\n%s",
                basename($file),
                implode("\n", $unfinished)
            )
        );
    }

    /**
     * Zerlegt die Datei in Anweisungen und liefert jene, die auf `$this->table(...)`
     * aufsetzen - entweder direkt verkettet oder über eine Zwischenvariable.
     *
     * @return array<int, string> Zeilennummer => Anweisung
     */
    private function tableStatements(string $file): array
    {
        $source = file_get_contents($file);
        self::assertIsString($source, 'Migration nicht lesbar: ' . $file);

        // Nur der Rumpf zählt; Doc-Blöcke und Konstanten enthalten kein Phinx-Chaining.
        $statements = [];
        $line = 1;
        $buffer = '';
        $bufferLine = 1;
        $length = strlen($source);

        for ($i = 0; $i < $length; $i++) {
            $char = $source[$i];
            if ($buffer === '') {
                $bufferLine = $line;
            }
            $buffer .= $char;
            if ($char === "\n") {
                $line++;
            }
            if ($char !== ';') {
                continue;
            }
            $statements[$bufferLine] = $buffer;
            $buffer = '';
        }

        $tableVariables = [];
        $relevant = [];
        foreach ($statements as $statementLine => $statement) {
            if (preg_match('/(\$\w+)\s*=\s*\$this->table\(/', $statement, $match) === 1) {
                // `$table = $this->table(...)` schließt für sich nichts ab; die
                // Aktionen hängen an der Variablen und werden dort geprüft.
                $tableVariables[$match[1]] = true;
                continue;
            }

            if (strpos($statement, '$this->table(') !== false) {
                $relevant[$statementLine] = $statement;
                continue;
            }

            foreach (array_keys($tableVariables) as $variable) {
                if (preg_match('/' . preg_quote($variable, '/') . '\s*(?:\r?\n\s*)?->/', $statement) === 1) {
                    $relevant[$statementLine] = $statement;
                    break;
                }
            }
        }

        return $relevant;
    }

    private function hasPendingAction(string $statement): bool
    {
        foreach (self::PENDING_ACTIONS as $action) {
            if (preg_match('/->' . $action . '\s*\(/', $statement) === 1) {
                return true;
            }
        }

        return false;
    }

    private function isCommitted(string $statement): bool
    {
        foreach (self::TERMINATORS as $terminator) {
            if (preg_match('/->' . $terminator . '\s*\(\s*\)/', $statement) === 1) {
                return true;
            }
        }

        return false;
    }

    private function condense(string $statement): string
    {
        $condensed = trim((string) preg_replace('/\s+/', ' ', $statement));

        return strlen($condensed) > 120 ? substr($condensed, 0, 117) . '...' : $condensed;
    }
}
