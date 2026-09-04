<?php

declare(strict_types=1);

namespace Tests\Unit\Models;

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
     * @return list<string>
     */
    private function columnsOf(string $table): array
    {
        if (self::$columnsByTable === null) {
            self::$columnsByTable = [];
            $connection = Capsule::connection();

            foreach ($connection->select('SHOW TABLES') as $row) {
                $name = (string) current((array) $row);
                self::$columnsByTable[$name] = array_map(
                    static fn ($column): string => (string) ((array) $column)['Field'],
                    $connection->select('SHOW COLUMNS FROM `' . $name . '`')
                );
            }
        }

        return self::$columnsByTable[$table] ?? [];
    }
}
