<?php

declare(strict_types=1);

namespace Tests\Unit\Controllers;

use PHPUnit\Framework\TestCase;

/**
 * Ein Controller schreibt nie selbst und liest Listen nicht selbst (siehe
 * instructions/data-access.md).
 *
 * Die Listen unten sind eine Sperrklinke wie in ControllerLayeringTest: Sie nennen je
 * Controller die Zahl der direkten Zugriffe beim Anlegen des Tests. Eine neue Stelle
 * macht den Test rot, eine abgebaute ebenfalls, bis die Liste nachgezogen ist. Wer
 * einen Controller ohnehin anfasst, zieht seine Zugriffe in die Persistenz- bzw.
 * Abfrageschicht um und streicht den Eintrag.
 *
 * Gezählt wird die Quelle ohne Kommentare (token_get_all). Ein Schreibaufruf zählt,
 * wenn seine Aufrufkette nicht bei einer Eigenschaft des Controllers beginnt
 * (`$this->taskWriter->update()`); ein Lesezugriff, wenn er statisch auf einer
 * importierten Modellklasse läuft - außer find/findOrFail, auch mit vorgeladenen
 * Beziehungen (`Song::with([...])->find($id)`).
 */
final class ControllerDataAccessTest extends TestCase
{
    /** @var list<string> */
    private const WRITE_METHODS = [
        'save', 'delete', 'update', 'sync', 'syncWithoutDetaching', 'attach', 'detach', 'toggle',
        'create', 'createMany', 'upsert', 'updateOrCreate', 'firstOrCreate', 'insert', 'insertGetId',
        'destroy', 'forceDelete', 'restore', 'increment', 'decrement', 'updateExistingPivot', 'push', 'touch',
    ];

    /** @var list<string> */
    private const SINGLE_RECORD = ['find', 'findOrFail'];

    /** @var array<string, int> Controller-Kurzname => Zahl der direkten Schreibaufrufe */
    private const KNOWN_DIRECT_WRITES = [
        'AppSettingController' => 1,
        'AuthController' => 3,
        'BudgetController' => 7,
        'CategoryController' => 4,
        'EvaluationController' => 1,
        'EventController' => 4,
        'EventTypeController' => 3,
        'FinanceController' => 3,
        'NewsletterController' => 4,
        'PasswordResetController' => 8,
        'PollController' => 6,
        'ProfileController' => 5,
        'ProjectController' => 2,
        'ProjectSongAssignmentController' => 3,
        'RegistrationController' => 2,
        'RoleController' => 3,
        'SponsorController' => 3,
        'SponsorPackageController' => 5,
        'SponsoringContactController' => 5,
        'SponsoringDashboardController' => 1,
        'TaskController' => 4,
        'UserController' => 2,
        'VoiceGroupController' => 6,
    ];

    /** @var array<string, int> Controller-Kurzname => Zahl der direkten Listenabfragen */
    private const KNOWN_DIRECT_READS = [
        // Darunter zwölf AppSetting::putText(): Seit Review-Lauf 77 schreibt der Controller über
        // diesen Modell-Helfer statt über updateOrCreate(). Der Zähler kennt putText nicht als
        // Schreibmethode und führt die Aufrufe deshalb hier - es bleiben Schreibzugriffe.
        'AppSettingController' => 14,
        'AttachmentController' => 1,
        'AttendanceController' => 1,
        'AuthController' => 5,
        'BudgetController' => 4,
        'CategoryController' => 2,
        'DashboardController' => 6,
        'DownloadController' => 3,
        'EvaluationController' => 3,
        'EventController' => 10,
        'EventSeriesController' => 1,
        'EventTypeController' => 1,
        'FileBrowserController' => 4,
        'FileController' => 1,
        'FileDetailController' => 3,
        'FinanceAccountController' => 1,
        'FinanceController' => 11,
        'NewsletterController' => 11,
        'NewsletterTemplateController' => 6,
        'PasswordResetController' => 10,
        'PollController' => 4,
        'ProfileController' => 5,
        'ProjectSongAssignmentController' => 1,
        'RegistrationController' => 2,
        'RoleController' => 3,
        'ScalewayWebhookController' => 1,
        'SponsorController' => 4,
        'SponsorPackageController' => 1,
        'SponsoringAttachmentController' => 3,
        'SponsoringContactController' => 3,
        'SponsoringDashboardController' => 13,
        'TaskController' => 3,
        'UserController' => 18,
        'VoiceGroupController' => 5,
        'WebmailController' => 1,
    ];

    /** @var list<string> */
    private array $temporaryFiles = [];

    protected function tearDown(): void
    {
        foreach ($this->temporaryFiles as $file) {
            @unlink($file);
        }
        $this->temporaryFiles = [];

        parent::tearDown();
    }

    public function testNoControllerTouchesTheDatabaseBeyondTheKnownCases(): void
    {
        $writes = [];
        $reads = [];

        foreach (self::controllerFiles() as $file) {
            $name = basename($file, '.php');
            if (($count = self::directWrites($file)) > 0) {
                $writes[$name] = $count;
            }
            if (($count = self::directReads($file)) > 0) {
                $reads[$name] = $count;
            }
        }

        ksort($writes);
        ksort($reads);
        self::assertSame(self::KNOWN_DIRECT_WRITES, $writes, 'Direkte Schreibzugriffe im Controller.');
        self::assertSame(self::KNOWN_DIRECT_READS, $reads, 'Direkte Listenabfragen im Controller.');
    }

    public function testWritesOnModelsAndRelationsCount(): void
    {
        $file = $this->snippet(
            '$song->update([]); $song->categories()->sync([]); Song::create([]); '
            . '(clone $relation)->delete(); $existing?->delete();'
        );

        self::assertSame(5, self::directWrites($file));
    }

    public function testWritesThroughAnInjectedDependencyDoNotCount(): void
    {
        $file = $this->snippet(
            '$this->attachments->deleteForEntity(\'x\', 1, 2); $this->songs->update($song, []);'
        );

        self::assertSame(0, self::directWrites($file));
    }

    public function testWritingOnWhatAControllerMethodReturnsCounts(): void
    {
        $file = $this->snippet('$this->findList(1)->delete(); $this->update(1);');

        self::assertSame(1, self::directWrites($file));
    }

    public function testListReadsOnModelsCount(): void
    {
        $file = $this->snippet(
            'Song::with([\'a\'])->orderBy(\'title\')->get(); Category::orderBy(\'name\')->get();'
        );

        self::assertSame(2, self::directReads($file));
    }

    public function testSingleRecordLookupsDoNotCount(): void
    {
        $file = $this->snippet(
            'Song::find(1); Song::findOrFail(1); Song::with([\'a\'])->find(1); '
            . 'Song::with([\'a\'])->findOrFail(1); Song::class;'
        );

        self::assertSame(0, self::directReads($file));
    }

    public function testStaticCallsOnNonModelsDoNotCount(): void
    {
        $file = $this->snippet('Carbon::now(); InputValidator::asString($x); Carbon::create(2026, 1, 1);');

        self::assertSame(0, self::directReads($file));
        self::assertSame(0, self::directWrites($file));
    }

    /** @return list<string> */
    public static function controllerFiles(): array
    {
        $base = dirname(__DIR__, 3) . '/src/Controllers';
        $files = array_merge(glob($base . '/*.php') ?: [], glob($base . '/Oidc/*.php') ?: []);
        self::assertGreaterThan(40, count($files), 'Es wurden kaum Controller gefunden.');

        return $files;
    }

    public static function directWrites(string $file): int
    {
        $tokens = self::tokens($file);
        $count = 0;

        foreach ($tokens as $i => $token) {
            if (!self::isCall($tokens, $i) || !in_array($token[1], self::WRITE_METHODS, true)) {
                continue;
            }

            $before = $tokens[$i - 1];
            $head = self::chainHead($tokens, $i - 1);
            if ($before[0] === T_DOUBLE_COLON) {
                // Direkt statisch: nur auf Modellen und der Datenbankfassade ein Schreibzugriff
                // (Carbon::create() ist keiner).
                $models = self::models($tokens);
                if (isset($models[$head]) || in_array($head, ['Capsule', 'DB'], true)) {
                    $count++;
                }
                continue;
            }

            // $this->update(...) ruft eine Methode des Controllers selbst; erst $this->finde()->update(...)
            // schreibt auf dem, was sie liefert.
            $directOnThis = $head === 'this-method'
                && is_array($tokens[$i - 2] ?? null) && $tokens[$i - 2][1] === '$this';
            if ($head !== 'this-property' && $head !== 'self' && !$directOnThis) {
                $count++;
            }
        }

        return $count;
    }

    public static function directReads(string $file): int
    {
        $tokens = self::tokens($file);
        $models = self::models($tokens);
        $count = 0;

        foreach ($tokens as $i => $token) {
            if (!self::isCall($tokens, $i) || $tokens[$i - 1][0] !== T_DOUBLE_COLON) {
                continue;
            }
            $method = $token[1];
            $head = self::chainHead($tokens, $i - 1);
            if (!isset($models[$head]) || in_array($method, self::SINGLE_RECORD, true)
                || in_array($method, self::WRITE_METHODS, true)) {
                continue;
            }
            if ($method === 'with' && self::followedByFind($tokens, $i + 1)) {
                continue;
            }
            $count++;
        }

        return $count;
    }

    /** Legt eine Klasse mit dem Schnipsel im Methodenrumpf an und liefert den Dateipfad. */
    private function snippet(string $body): string
    {
        $file = tempnam(sys_get_temp_dir(), 'dataaccess');
        $this->temporaryFiles[] = $file;
        file_put_contents(
            $file,
            "<?php\nnamespace Snippet;\nuse App\\Models\\Song;\nuse App\\Models\\Category;\n"
            . "class A { public function f() { {$body} } }\n"
        );

        return $file;
    }

    /** @return list<array{0: int|string, 1: string, 2?: int}|string> Token ohne Leerraum und Kommentare */
    private static function tokens(string $file): array
    {
        return array_values(array_filter(
            token_get_all((string) file_get_contents($file)),
            static fn ($t): bool => !is_array($t) || !in_array($t[0], [T_WHITESPACE, T_COMMENT, T_DOC_COMMENT], true)
        ));
    }

    /**
     * Die per `use App\Models\X;` importierten Kurznamen.
     *
     * @param list<mixed> $tokens
     * @return array<string, true>
     */
    private static function models(array $tokens): array
    {
        $models = [];
        foreach ($tokens as $i => $token) {
            if (is_array($token) && $token[0] === T_NAME_QUALIFIED && str_starts_with($token[1], 'App\\Models\\')
                && is_array($tokens[$i - 1] ?? null) && $tokens[$i - 1][0] === T_USE) {
                $models[substr($token[1], (int) strrpos($token[1], '\\') + 1)] = true;
            }
        }

        return $models;
    }

    /** @param list<mixed> $tokens */
    private static function isCall(array $tokens, int $i): bool
    {
        $token = $tokens[$i];
        $before = $tokens[$i - 1] ?? null;

        return is_array($token) && $token[0] === T_STRING
            && ($tokens[$i + 1] ?? null) === '('
            && is_array($before)
            && in_array($before[0], [T_OBJECT_OPERATOR, T_NULLSAFE_OBJECT_OPERATOR, T_DOUBLE_COLON], true);
    }

    /**
     * Steht hinter dem Aufruf, der bei $open (der öffnenden Klammer) beginnt, direkt
     * `->find(` oder `->findOrFail(`?
     *
     * @param list<mixed> $tokens
     */
    private static function followedByFind(array $tokens, int $open): bool
    {
        $depth = 0;
        for ($i = $open, $n = count($tokens); $i < $n; $i++) {
            if ($tokens[$i] === '(') {
                $depth++;
            } elseif ($tokens[$i] === ')') {
                $depth--;
                if ($depth === 0) {
                    break;
                }
            }
        }

        $arrow = $tokens[$i + 1] ?? null;
        $name = $tokens[$i + 2] ?? null;

        return is_array($arrow) && $arrow[0] === T_OBJECT_OPERATOR
            && is_array($name) && in_array($name[1], self::SINGLE_RECORD, true)
            && ($tokens[$i + 3] ?? null) === '(';
    }

    /**
     * Der Anfang einer Aufrufkette, von einem `->` oder `::` rückwärts gelesen:
     * 'this-property' für `$this->eigenschaft->...`, 'self' für `$this->methode()`,
     * `self::`, `static::`, der Klassenname bei `Modell::...`, 'var' für `$x->...`,
     * sonst 'other' (etwa `(clone $x)->...`).
     *
     * @param list<mixed> $tokens
     */
    private static function chainHead(array $tokens, int $operator): string
    {
        $i = $operator - 1;
        while ($i >= 0) {
            $token = $tokens[$i];
            if ($token === ')' || $token === ']') {
                $open = $token === ')' ? '(' : '[';
                $depth = 0;
                for (; $i >= 0; $i--) {
                    if ($tokens[$i] === $token) {
                        $depth++;
                    } elseif ($tokens[$i] === $open) {
                        $depth--;
                        if ($depth === 0) {
                            break;
                        }
                    }
                }
                $i--;
                continue;
            }
            if (is_array($token) && $token[0] === T_STRING) {
                $previous = $tokens[$i - 1] ?? null;
                if (is_array($previous) && in_array(
                    $previous[0],
                    [T_OBJECT_OPERATOR, T_NULLSAFE_OBJECT_OPERATOR, T_DOUBLE_COLON],
                    true
                )) {
                    $i -= 2;
                    continue;
                }

                return in_array(strtolower($token[1]), ['self', 'static', 'parent'], true) ? 'self' : $token[1];
            }
            if (is_array($token) && $token[0] === T_VARIABLE) {
                if ($token[1] !== '$this') {
                    return 'var';
                }
                $next = $tokens[$i + 1] ?? null;
                $isProperty = is_array($next) && $next[0] === T_OBJECT_OPERATOR && ($tokens[$i + 3] ?? null) !== '(';

                return $isProperty ? 'this-property' : 'this-method';
            }
            if (is_array($token) && in_array($token[0], [T_NAME_FULLY_QUALIFIED, T_NAME_QUALIFIED], true)) {
                return substr($token[1], (int) strrpos($token[1], '\\') + 1);
            }

            return 'other';
        }

        return 'other';
    }
}
