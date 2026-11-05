<?php

declare(strict_types=1);

namespace Tests\Unit\Models;

use App\Util\EntityType;
use Carbon\CarbonInterface;
use Illuminate\Database\Capsule\Manager as Capsule;
use Illuminate\Database\Eloquent\Builder as EloquentBuilder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOneOrMany;
use Illuminate\Database\Eloquent\Relations\MorphTo;
use Illuminate\Database\Eloquent\Relations\Relation;
use Illuminate\Database\Query\Builder as QueryBuilder;
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
    /**
     * Wertelisten in den Modellen und die ENUM-Spalte, die jede spiegelt. Nur
     * Namen, die Vollständigkeit behaupten - eine bewusste Teilmenge heißt
     * anders (siehe `Attendance::RECORDED_STATUSES`).
     *
     * Hieß bis hierher STATUS_LIST_COLUMNS und deckte nur die drei
     * Statusspalten ab. Die Frage ist aber bei jeder ENUM-Spalte dieselbe, und
     * drei Listen, die im Kommentar ausdrücklich ihr ENUM zu spiegeln
     * behaupten, standen damit ungeprüft da: `AudienceFilterCondition::CATEGORIES`,
     * `User::CALENDAR_TASK_FEEDS` und `User::CALENDAR_TASK_FORMATS`.
     *
     * Bei den Kategorien wäre das Auseinanderlaufen besonders still verlaufen:
     * `AudienceFilter::conditionSet()` läuft über `CATEGORIES` und lässt jede
     * Bedingung mit einer dort nicht genannten Kategorie aus der Zielgruppe
     * fallen - ohne Fehler, ohne Hinweis. Eine per Migration ergänzte Kategorie
     * hätte Newsletter und Termine an einen anderen Kreis geschickt als
     * eingestellt.
     *
     * `RESOURCE_TYPES` kam mit 20261029120000 dazu: Bis dahin war
     * `song_resources.resource_type` ein `varchar(32)`, und die Liste konnte
     * nichts spiegeln. Der Name heißt deshalb nicht `TYPES` - das hätte eine
     * Spalte `type` behauptet, die es hier nicht gibt.
     *
     * `MailQueue::SUPPORTED_MAIL_TYPES` fehlt hier bewusst: Es hat mit
     * MailQueueMailTypesTest einen eigenen Wächter, der zusätzlich die Labels
     * prüft.
     *
     * Mit Lauf 65 kamen die letzten sechs Namen dazu. Bis dahin hatten elf
     * ENUM-Spalten keine Liste am Modell, die dieser Test hätte prüfen können -
     * `polls.answer_kind`, `finance_accounts.type`, `finance_revisions.action`,
     * `budget_categories.type`, `sponsors.type`, `sponsoring_contacts.type`,
     * `attachments.delivery_mode`, `mail_delivery_events.source_channel`,
     * `user_mail_accounts.imap_encryption` und die beiden `source_type`. Teils
     * gab es die Einzelkonstanten schon, teils standen die Werte als Aufzählung
     * in einem Controller oder Dienst. Beides hält eine Migration nicht auf:
     * Genau so überlebte `queued` die Migration 20260419223000 ein halbes Jahr.
     *
     * @var array<string, string>
     */
    private const ENUM_MIRRORING_LISTS = [
        'STATUSES' => 'status',
        'SUPPORTED_STATUSES' => 'status',
        'DELIVERY_STATUSES' => 'delivery_status',
        'CATEGORIES' => 'category',
        'CALENDAR_TASK_FEEDS' => 'calendar_task_feed',
        'CALENDAR_TASK_FORMATS' => 'calendar_task_format',
        'TYPES' => 'type',
        'PAYMENT_METHODS' => 'payment_method',
        'PRIORITIES' => 'priority',
        'RESOURCE_TYPES' => 'resource_type',
        'ACTIONS' => 'action',
        'ANSWER_KINDS' => 'answer_kind',
        'DELIVERY_MODES' => 'delivery_mode',
        'SOURCE_CHANNELS' => 'source_channel',
        'SOURCE_TYPES' => 'source_type',
        'IMAP_ENCRYPTIONS' => 'imap_encryption',
    ];

    /**
     * ENUM-Spalten, die bewusst keine Werteliste am Modell haben.
     *
     * Die Liste bleibt klein und wird einzeln begründet - sie ist der Ort, an
     * dem jemand bewusst sagt "diese Spalte wird anderswo bewacht".
     *
     * @var array<string, string> "tabelle.spalte" => Begründung
     */
    private const ENUM_WITHOUT_LIST = [
        // MailQueueMailTypesTest hält `MailQueue::SUPPORTED_MAIL_TYPES` gegen
        // das ENUM und prüft zusätzlich, dass jede Mailart ein Label hat.
        'mail_queue.mail_type' => 'Eigener Wächter in MailQueueMailTypesTest.',
    ];

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
     * Eine Werteliste im Modell muss genau die Werte nennen, die das ENUM erlaubt.
     *
     * Ein ENUM wird per Migration erweitert, die Liste im Modell bleibt stehen:
     * Der neue Wert wird dann geschrieben, aber von keiner Prüfmethode erkannt.
     * Genau so überlebte `queued` die Migration 20260419223000 ein halbes Jahr,
     * ohne dass `NewsletterRecipient` es kannte - der normale Zustand direkt
     * nach dem Versand fiel durch `isPending()`, `isSent()` und `isFailed()`
     * gleichermaßen hindurch.
     *
     * Geprüft werden nur Listen, deren Name Vollständigkeit behauptet, und
     * jede gegen die Spalte, die sie spiegelt. `Attendance::RECORDED_STATUSES`
     * ist bewusst eine Teilmenge - `unknown` zählt dort nicht als Erfassung -
     * und heißt deshalb anders.
     *
     * @param class-string<Model> $class
     */
    #[\PHPUnit\Framework\Attributes\DataProvider('modelProvider')]
    public function testWertelistenDeckenDasEnumAb(string $class): void
    {
        $model = new $class();
        $reflection = new ReflectionClass($class);
        $checked = 0;

        foreach (self::ENUM_MIRRORING_LISTS as $name => $column) {
            if (!$reflection->hasConstant($name)) {
                continue;
            }

            $allowed = $this->enumValuesOf($model->getTable(), $column);

            self::assertNotSame([], $allowed, sprintf(
                '%s::%s gibt es, aber "%s.%s" ist kein ENUM.',
                $class,
                $name,
                $model->getTable(),
                $column
            ));

            /** @var list<string> $declared */
            $declared = array_map('strval', (array) $reflection->getConstant($name));
            sort($declared);
            sort($allowed);

            self::assertSame($allowed, $declared, sprintf(
                '%s::%s weicht von "%s.%s" ab. ENUM: %s',
                $class,
                $name,
                $model->getTable(),
                $column,
                implode(', ', $allowed)
            ));
            $checked++;
        }

        self::assertGreaterThanOrEqual(0, $checked);
    }

    /**
     * Umgekehrte Richtung: Jede ENUM-Spalte im Schema braucht eine Werteliste
     * an ihrem Modell.
     *
     * Der Test oben prüft die Listen, die es gibt. Er sagt nichts über die, die
     * fehlen - und genau daran lag es: Elf ENUM-Spalten hatten keine, und der
     * Test lief über sie hinweg, ohne etwas zu melden. Eine neue ENUM-Spalte
     * fällt jetzt sofort auf, und zwar an derselben Stelle, an der auch die
     * Abweichung einer bestehenden Liste auffällt.
     *
     * Ersetzt die frühere Zählprobe ("mindestens acht Listen gefunden"). Die
     * sollte verhindern, dass der Test leer durchläuft, hat aber nur die
     * Untergrenze bewacht: Dass eine Spalte ohne Liste dasteht, konnte sie
     * nicht sehen. Diese Prüfung leistet beides - sie läuft nie leer, weil das
     * Schema die Liste der Fälle vorgibt.
     */
    public function testJedeEnumSpalteHatEineWerteliste(): void
    {
        $listsByColumn = [];

        foreach (self::modelProvider() as [$class]) {
            $reflection = new ReflectionClass($class);
            $table = (new $class())->getTable();

            foreach (self::ENUM_MIRRORING_LISTS as $name => $column) {
                if ($reflection->hasConstant($name)) {
                    $listsByColumn[$table . '.' . $column] = true;
                }
            }
        }

        $problems = [];
        $checked = 0;

        foreach ($this->enumColumns() as $qualified) {
            $checked++;

            if (isset(self::ENUM_WITHOUT_LIST[$qualified]) || isset($listsByColumn[$qualified])) {
                continue;
            }

            $problems[] = sprintf(
                '%s ist ein ENUM, aber kein Modell führt dazu eine Werteliste. Entweder eine Liste '
                    . 'anlegen und ihren Namen in ENUM_MIRRORING_LISTS eintragen oder in '
                    . 'ENUM_WITHOUT_LIST begründen.',
                $qualified
            );
        }

        self::assertSame([], $problems, implode("\n", $problems));
        self::assertGreaterThan(20, $checked, 'Es wurde kaum eine ENUM-Spalte gefunden.');
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

            // Vor BelongsTo, denn MorphTo erbt davon: Ein MorphTo hat keine feste
            // Gegenseite, `getOwnerKeyName()` ist an einer leeren Instanz null.
            // Geprüft werden deshalb die beiden eigenen Spalten und der
            // Schlüssel jeder Klasse, die die Morph-Map nennt.
            if ($relation instanceof MorphTo) {
                $this->requireColumn($problems, $label, $model->getTable(), $relation->getForeignKeyName());
                $this->requireColumn($problems, $label, $model->getTable(), $relation->getMorphType());

                foreach (EntityType::map() as $alias => $target) {
                    $related = new $target();
                    $this->requireColumn(
                        $problems,
                        $label . ' über "' . $alias . '"',
                        $related->getTable(),
                        $related->getKeyName()
                    );
                }
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
     * Eine Relation auf eine Tabelle mit `sort_order` muss auch danach sortieren.
     *
     * `sort_order` gibt es nur zu einem Zweck: Die Zeilen sollen in einer
     * festgelegten Reihenfolge stehen, und zwar in der eingestellten, nicht in
     * der der Datenbank. Steht die Sortierung nicht an der Relation, hängt sie
     * am Aufrufer - und dann hat sie der eine und der andere nicht. Genau so
     * stand es bei `Song::categories()`: Die Einzelansicht lud die Kategorien
     * mit `orderBy('sort_order')` vor, die Übersicht ohne, und dieselben
     * Abzeichen standen je nach Seite anders herum. Bei
     * `SheetArchive::lineItems()` dasselbe - die Einzelansicht sortiert, die
     * JSON-Antwort des Speichern-Endpunkts nicht.
     *
     * Gleiche Begründung wie bei `HasEntityComments::comments()`,
     * `Task::activities()` und `Event::audienceFilters()`: Ohne `ORDER BY` ist
     * die Reihenfolge nicht zugesichert, und sie gehört an die eine Stelle, die
     * jeder Aufrufer ohnehin benutzt.
     *
     * @param class-string<Model> $class
     */
    #[\PHPUnit\Framework\Attributes\DataProvider('modelProvider')]
    public function testRelationenSortierenNachSortOrder(string $class): void
    {
        $problems = [];

        foreach ($this->relationMethodsOf($class) as $name => $relation) {
            // Nur Relationen mit mehreren Zeilen: Bei einer einzelnen - belongsTo
            // auf das Konto einer Buchung, hasOne auf die Zielgruppe einer
            // Freigabe - gibt es keine Reihenfolge zu sichern.
            if (!$relation instanceof HasMany && !$relation instanceof BelongsToMany) {
                continue;
            }

            $table = $relation->getRelated()->getTable();

            if (!in_array('sort_order', $this->columnsOf($table), true)) {
                continue;
            }

            if (in_array('sort_order', $this->orderColumnsOf($relation), true)) {
                continue;
            }

            $problems[] = sprintf(
                '%s::%s() zeigt auf "%s" mit einer Spalte sort_order, sortiert aber nicht danach. '
                    . 'Die Sortierung gehört an die Relation, nicht in den Eager-Load eines einzelnen Aufrufers.',
                $class,
                $name,
                $table
            );
        }

        self::assertSame([], $problems, implode("\n", $problems));
    }

    /**
     * Eine parameterlose Methode, die einen Query Builder liefert, muss auf
     * `Query` enden.
     *
     * Eloquent hält beim Property-Zugriff jede parameterlose Methode für eine
     * Relation: `$model->foo` ruft `foo()` auf und wirft "must return a
     * relationship instance", wenn dabei keine Relation herauskommt. Ein
     * Template schreibt aber `projekt.events`, ohne zu wissen, dass dahinter
     * keine Relation steht - und bekommt dann keinen leeren Wert, sondern einen
     * Abbruch. Genau so stand `Project::events()` da, bis es `eventsQuery()`
     * hieß.
     *
     * Die Endung ist deshalb kein Geschmack, sondern die Warnung an der
     * Aufrufstelle.
     *
     * @param class-string<Model> $class
     */
    #[\PHPUnit\Framework\Attributes\DataProvider('modelProvider')]
    public function testQueryMethodenHeissenAuchSo(string $class): void
    {
        $problems = [];

        foreach ($this->builderMethodsOf($class) as $name) {
            if (str_ends_with($name, 'Query')) {
                continue;
            }

            $problems[] = sprintf(
                '%s::%s() liefert einen Query Builder, heißt aber nicht "...Query". '
                    . 'Ein Property-Zugriff darauf bricht mit "must return a relationship instance" ab.',
                $class,
                $name
            );
        }

        self::assertSame([], $problems, implode("\n", $problems));
    }

    /**
     * Eine Methode mit dem Präfix `scope` gehört Eloquent - auch eine private.
     *
     * `hasNamedScope()` fragt nur `method_exists()`, und das findet auch eine
     * private Methode. Ein `->toKeyColumns()` auf dem Query Builder gilt damit
     * als benannter Scope; Eloquent ruft ihn aus `Model::callNamedScope()` auf,
     * wo die private Methode der Unterklasse nicht sichtbar ist, und landet
     * über `__call()` in einer BadMethodCallException über einen Namen, den es
     * nie gab. Aus einem klaren "diese Methode gibt es nicht" wird so ein
     * Irrweg durch zwei Weiterleitungen.
     *
     * Ein privater Helfer heißt deshalb nicht `scope...`. Wer wirklich einen
     * Scope schreibt, macht ihn öffentlich.
     *
     * @param class-string<Model> $class
     */
    #[\PHPUnit\Framework\Attributes\DataProvider('modelProvider')]
    public function testScopeMethodenSindNichtPrivat(string $class): void
    {
        $problems = [];

        foreach ((new ReflectionClass($class))->getMethods() as $method) {
            if ($method->getDeclaringClass()->getName() !== $class) {
                continue;
            }

            if (!str_starts_with($method->getName(), 'scope') || $method->getName() === 'scope') {
                continue;
            }

            if ($method->isPublic()) {
                continue;
            }

            $problems[] = sprintf(
                '%s::%s() ist nicht öffentlich, trägt aber das für Scopes reservierte Präfix. '
                    . 'hasNamedScope() meldet den Scope trotzdem, und der Aufruf scheitert erst '
                    . 'tief in Eloquent.',
                $class,
                $method->getName()
            );
        }

        self::assertSame([], $problems, implode("\n", $problems));
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
     * Die parameterlosen Methoden eines Modells, die einen Query Builder und
     * keine Relation liefern.
     *
     * @param class-string<Model> $class
     * @return list<string>
     */
    private function builderMethodsOf(string $class): array
    {
        $model = new $class();
        $names = [];

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
                continue;
            }

            if ($result instanceof EloquentBuilder || $result instanceof QueryBuilder) {
                $names[] = $method->name;
            }
        }

        return $names;
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
     * Die Spalten, nach denen eine Relation sortiert - ohne Tabellenpräfix,
     * damit ein qualifiziertes `audience_filters.id` genauso zählt wie ein
     * blankes `sort_order`.
     *
     * @param Relation<Model, Model, mixed> $relation
     * @return list<string>
     */
    private function orderColumnsOf(Relation $relation): array
    {
        $columns = [];

        foreach ($relation->getQuery()->getQuery()->orders ?? [] as $order) {
            $column = (string) ($order['column'] ?? '');

            if ($column === '') {
                continue;
            }

            $columns[] = str_contains($column, '.') ? substr(strrchr($column, '.') ?: '', 1) : $column;
        }

        return $columns;
    }

    /**
     * Alle ENUM-Spalten des Schemas als "tabelle.spalte".
     *
     * @return list<string>
     */
    private function enumColumns(): array
    {
        $this->loadSchema();

        $columns = [];

        foreach (self::$typesByTable ?? [] as $table => $types) {
            foreach ($types as $column => $type) {
                if (preg_match('/^enum\(/i', $type) === 1) {
                    $columns[] = $table . '.' . $column;
                }
            }
        }

        return $columns;
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
