<?php

declare(strict_types=1);

namespace Tests\Unit\Models;

use Carbon\CarbonInterface;
use Illuminate\Database\Capsule\Manager as Capsule;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasOneOrMany;
use Illuminate\Database\Eloquent\Relations\Relation;
use PHPUnit\Framework\TestCase;
use ReflectionClass;
use ReflectionMethod;
use Tests\Unit\Bootstrap;

/**
 * Hält die Modelle mit dem tatsächlichen Schema zusammen.
 *
 * Wird eine Spalte per Migration entfernt, bleiben `$fillable`-Einträge,
 * `$casts` und Relationen darauf lautlos stehen: Der Code lädt weiter, und erst
 * der erste Aufruf läuft in einen SQL-Fehler. Genau so überlebte
 * `songs.project_id` seinen `DROP COLUMN` in `Song::project()`,
 * `Project::songs()` und `Song::$fillable`.
 */
final class ModelSchemaConsistencyTest extends TestCase
{
    /** @var array<string, list<string>>|null */
    private static ?array $columnsByTable = null;

    /** @var array<string, array<string, string>>|null */
    private static ?array $typesByTable = null;

    protected function setUp(): void
    {
        parent::setUp();
        Bootstrap::setupTestDatabase();
    }

    /**
     * @return array<string, array{class-string<Model>}>
     */
    public static function modelProvider(): array
    {
        $cases = [];

        foreach (glob(dirname(__DIR__, 3) . '/src/Models/*.php') ?: [] as $file) {
            $shortName = basename($file, '.php');
            $class = 'App\\Models\\' . $shortName;

            if (!class_exists($class) || !is_subclass_of($class, Model::class)) {
                continue;
            }

            $cases[$shortName] = [$class];
        }

        return $cases;
    }

    /**
     * @param class-string<Model> $class
     */
    #[\PHPUnit\Framework\Attributes\DataProvider('modelProvider')]
    public function testMassenzuweisbareFelderExistierenAlsSpalte(string $class): void
    {
        $model = new $class();
        $columns = $this->columnsOf($model->getTable());

        self::assertNotSame([], $columns, sprintf('Tabelle "%s" fehlt in der Datenbank.', $model->getTable()));

        $unknown = array_values(array_diff($model->getFillable(), $columns));

        self::assertSame([], $unknown, sprintf(
            '%s::$fillable nennt Spalten, die es in "%s" nicht gibt: %s',
            $class,
            $model->getTable(),
            implode(', ', $unknown)
        ));
    }

    /**
     * Der Primärschlüssel eines Modells muss eine Spalte der Tabelle sein.
     *
     * Eloquent kennt keine zusammengesetzten Schlüssel: Bleibt bei einer Tabelle
     * mit `id => false` die Vorgabe `id` stehen, laufen alle Schreibzugriffe über
     * die Modellinstanz - `save()` auf einer geladenen Zeile, `delete()`,
     * `refresh()` - in "Unknown column 'id' in 'WHERE'". Auffallen kann das erst
     * im Betrieb, weil Anlegen und Lesen unberührt bleiben. Genau so blieb es in
     * `UserNotificationSetting` unbemerkt.
     *
     * @param class-string<Model> $class
     */
    #[\PHPUnit\Framework\Attributes\DataProvider('modelProvider')]
    public function testPrimaryKeyIstEineEchteSpalte(string $class): void
    {
        $model = new $class();
        $keyName = $model->getKeyName();
        $columns = $this->columnsOf($model->getTable());

        self::assertNotSame([], $columns, sprintf('Tabelle "%s" fehlt in der Datenbank.', $model->getTable()));

        self::assertContains($keyName, $columns, sprintf(
            '%s::$primaryKey nennt "%s" - diese Spalte gibt es in "%s" nicht.',
            $class,
            $keyName,
            $model->getTable()
        ));
    }

    /**
     * Zeitspalten müssen als Carbon aus dem Modell kommen, nicht als Zeichenkette.
     *
     * Bei `$timestamps = true` erledigt Eloquent das für `created_at` und
     * `updated_at` von selbst. Steht es auf `false` - und das tun die meisten
     * Modelle hier - bleibt die Spalte roher Text, bis sie in `$casts` steht. Ein
     * `->format()` oder ein Vergleich mit einem Datum läuft darauf auf, und in
     * Twig fällt es nicht auf, weil der `date`-Filter beides frisst.
     *
     * @param class-string<Model> $class
     */
    #[\PHPUnit\Framework\Attributes\DataProvider('modelProvider')]
    public function testZeitspaltenKommenAlsDatum(string $class): void
    {
        $model = new $class();
        $problems = [];

        foreach ($this->dateColumnsOf($model->getTable()) as $column) {
            $probe = new $class();
            $probe->setRawAttributes([$column => '2026-01-02 03:04:05'], true);

            if (!$probe->getAttribute($column) instanceof CarbonInterface) {
                $problems[] = sprintf('%s.%s kommt als Zeichenkette - der Cast fehlt.', $class, $column);
            }
        }

        self::assertSame([], $problems, implode("\n", $problems));
    }

    /**
     * Eine Statusliste im Modell muss genau die Werte nennen, die das ENUM erlaubt.
     *
     * Ein ENUM wird per Migration erweitert, die Liste im Modell bleibt stehen:
     * Der neue Wert wird dann geschrieben, aber von keiner Prüfmethode erkannt.
     * Genau so überlebte `queued` die Migration 20260419223000 ein halbes Jahr,
     * ohne dass `NewsletterRecipient` es kannte - der normale Zustand direkt
     * nach dem Versand fiel durch `isPending()`, `isSent()` und `isFailed()`
     * gleichermaßen hindurch.
     *
     * Geprüft werden nur Listen, deren Name Vollständigkeit behauptet.
     * `Attendance::RECORDED_STATUSES` ist bewusst eine Teilmenge - `unknown`
     * zählt dort nicht als Erfassung - und heißt deshalb anders.
     *
     * @param class-string<Model> $class
     */
    #[\PHPUnit\Framework\Attributes\DataProvider('modelProvider')]
    public function testStatuslistenDeckenDasEnumAb(string $class): void
    {
        $model = new $class();
        $reflection = new ReflectionClass($class);
        $checked = 0;

        foreach (['STATUSES', 'SUPPORTED_STATUSES'] as $name) {
            if (!$reflection->hasConstant($name)) {
                continue;
            }

            $allowed = $this->enumValuesOf($model->getTable(), 'status');

            self::assertNotSame([], $allowed, sprintf(
                '%s::%s gibt es, aber "%s.status" ist kein ENUM.',
                $class,
                $name,
                $model->getTable()
            ));

            /** @var list<string> $declared */
            $declared = array_map('strval', (array) $reflection->getConstant($name));
            sort($declared);
            sort($allowed);

            self::assertSame($allowed, $declared, sprintf(
                '%s::%s weicht von "%s.status" ab. ENUM: %s',
                $class,
                $name,
                $model->getTable(),
                implode(', ', $allowed)
            ));
            $checked++;
        }

        self::assertGreaterThanOrEqual(0, $checked);
    }

    /**
     * Schützt davor, dass der Test oben leer durchläuft, weil keine einzige
     * Statusliste mehr gefunden wird.
     */
    public function testEsGibtUeberhauptStatuslistenZuPruefen(): void
    {
        $found = 0;

        foreach (self::modelProvider() as [$class]) {
            $reflection = new ReflectionClass($class);

            foreach (['STATUSES', 'SUPPORTED_STATUSES'] as $name) {
                $found += $reflection->hasConstant($name) ? 1 : 0;
            }
        }

        self::assertGreaterThanOrEqual(3, $found, 'Es wurde kaum eine Statusliste erkannt.');
    }

    /**
     * @param class-string<Model> $class
     */
    #[\PHPUnit\Framework\Attributes\DataProvider('modelProvider')]
    public function testRelationenZeigenAufVorhandeneSpalten(string $class): void
    {
        $model = new $class();
        $checked = 0;
        $problems = [];

        foreach ($this->relationMethodsOf($class) as $name => $relation) {
            $checked++;
            $label = sprintf('%s::%s()', $class, $name);

            if ($relation instanceof BelongsToMany) {
                $pivot = $relation->getTable();
                $this->requireColumn($problems, $label, $pivot, $relation->getForeignPivotKeyName());
                $this->requireColumn($problems, $label, $pivot, $relation->getRelatedPivotKeyName());
                $this->requireColumn(
                    $problems,
                    $label,
                    $relation->getRelated()->getTable(),
                    $relation->getRelatedKeyName()
                );
                continue;
            }

            if ($relation instanceof HasOneOrMany) {
                $foreignKey = $relation->getForeignKeyName();
                $this->requireColumn($problems, $label, $relation->getRelated()->getTable(), $foreignKey);
                $this->requireColumn($problems, $label, $model->getTable(), $relation->getLocalKeyName());
                continue;
            }

            if ($relation instanceof BelongsTo) {
                $this->requireColumn($problems, $label, $model->getTable(), $relation->getForeignKeyName());
                $this->requireColumn(
                    $problems,
                    $label,
                    $relation->getRelated()->getTable(),
                    $relation->getOwnerKeyName()
                );
            }
        }

        self::assertSame([], $problems, implode("\n", $problems));
        self::assertGreaterThanOrEqual(0, $checked);
    }

    /**
     * Schützt davor, dass der Relationstest oben leer durchläuft, weil die
     * Reflexion keine einzige Relation mehr erkennt.
     */
    public function testDieReflexionFindetRelationenUeberhaupt(): void
    {
        $found = 0;

        foreach (self::modelProvider() as [$class]) {
            $found += count($this->relationMethodsOf($class));
        }

        self::assertGreaterThan(50, $found, 'Es wurden kaum Relationen erkannt - der Test liefe sonst leer durch.');
    }

    /**
     * @param class-string<Model> $class
     * @return array<string, Relation<Model, Model, mixed>>
     */
    private function relationMethodsOf(string $class): array
    {
        $model = new $class();
        $relations = [];

        foreach ((new ReflectionClass($class))->getMethods(ReflectionMethod::IS_PUBLIC) as $method) {
            if ($method->class !== $class || $method->getNumberOfParameters() > 0 || $method->isStatic()) {
                continue;
            }

            if (str_starts_with($method->name, 'get') || str_starts_with($method->name, 'scope')) {
                continue;
            }

            try {
                $result = $model->{$method->name}();
            } catch (\Throwable) {
                continue;
            }

            if ($result instanceof Relation) {
                $relations[$method->name] = $result;
            }
        }

        return $relations;
    }

    /**
     * @param list<string> $problems
     */
    private function requireColumn(array &$problems, string $label, string $table, string $column): void
    {
        $column = str_contains($column, '.') ? substr(strrchr($column, '.') ?: '', 1) : $column;
        $columns = $this->columnsOf($table);

        if ($columns === []) {
            $problems[] = sprintf('%s verweist auf die unbekannte Tabelle "%s".', $label, $table);
            return;
        }

        if (!in_array($column, $columns, true)) {
            $problems[] = sprintf('%s verweist auf "%s.%s" - diese Spalte gibt es nicht.', $label, $table, $column);
        }
    }

    /**
     * Die Zeitspalten einer Tabelle - `date`, `datetime` und `timestamp`.
     *
     * @return list<string>
     */
    private function dateColumnsOf(string $table): array
    {
        $this->loadSchema();

        $dateColumns = [];

        foreach (self::$typesByTable[$table] ?? [] as $column => $type) {
            if (in_array(strtolower($type), ['date', 'datetime', 'timestamp'], true)) {
                $dateColumns[] = $column;
            }
        }

        return $dateColumns;
    }

    /**
     * Die zugelassenen Werte einer ENUM-Spalte. Leer, wenn die Spalte kein ENUM
     * ist oder es sie nicht gibt.
     *
     * @return list<string>
     */
    private function enumValuesOf(string $table, string $column): array
    {
        $this->loadSchema();

        $type = self::$typesByTable[$table][$column] ?? '';

        if (!preg_match("/^enum\\((.*)\\)$/i", $type, $match)) {
            return [];
        }

        preg_match_all("/'((?:[^']|'')*)'/", $match[1], $values);

        return array_map(static fn (string $value): string => str_replace("''", "'", $value), $values[1]);
    }

    /**
     * @return list<string>
     */
    private function columnsOf(string $table): array
    {
        $this->loadSchema();

        return self::$columnsByTable[$table] ?? [];
    }

    private function loadSchema(): void
    {
        if (self::$columnsByTable !== null) {
            return;
        }

        self::$columnsByTable = [];
        self::$typesByTable = [];
        $connection = Capsule::connection();

        foreach ($connection->select('SHOW TABLES') as $row) {
            $name = (string) current((array) $row);
            $names = [];
            $types = [];

            foreach ($connection->select('SHOW COLUMNS FROM `' . $name . '`') as $column) {
                $definition = (array) $column;
                $field = (string) $definition['Field'];
                $names[] = $field;
                $types[$field] = (string) $definition['Type'];
            }

            self::$columnsByTable[$name] = $names;
            self::$typesByTable[$name] = $types;
        }
    }
}
