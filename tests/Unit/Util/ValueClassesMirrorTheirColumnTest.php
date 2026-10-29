<?php

declare(strict_types=1);

namespace Tests\Unit\Util;

use Illuminate\Database\Capsule\Manager as Capsule;
use PHPUnit\Framework\TestCase;
use ReflectionClass;
use ReflectionMethod;
use Tests\Unit\Bootstrap;

/**
 * Hält die Wertelisten in `src/Util` mit dem tatsächlichen Schema zusammen.
 *
 * `ModelSchemaConsistencyTest` tut das für die Listen an den Modellen, läuft
 * aber nur über `src/Models`. Eine Werteliste muss nicht dort liegen: Die
 * Status einer Sponsoring-Vereinbarung stehen in `App\Util\SponsorshipStatus`,
 * weil zu ihnen auch Labels, Farben und Auswertungsgruppen gehören, die an
 * einem Modell nichts zu suchen haben. Der Kommentar dieser Klasse sagt
 * ausdrücklich: "Wer einen Status ergänzt, ändert nur noch diese Klasse und die
 * Enum-Definition der Spalte" - zwei Stellen von Hand, und kein Test hat sie
 * bisher verglichen. `SponsorshipStatusFeatureTest` prüft `all()` nur gegen
 * eine fest eingetragene Liste, nicht gegen die Spalte. Aufgefallen im
 * rotierenden Review-Lauf 58, entschieden in der Nacharbeit dazu.
 *
 * Der Test hat drei Teile, weil es drei Sorten von Wertelisten gibt: solche,
 * die ein ENUM spiegeln; solche, deren Spalte (noch) ein `varchar` ist; und
 * solche, die überhaupt keiner Spalte entsprechen. Für jede Sorte wird etwas
 * anderes geprüft - und die Zuordnung selbst wird mitgeprüft, damit eine neue
 * Werteliste nicht einfach durchfällt.
 */
final class ValueClassesMirrorTheirColumnTest extends TestCase
{
    /**
     * Wertelisten, die ein ENUM spiegeln, mit Tabelle und Spalte. `all()` muss
     * genau die Werte des ENUM liefern.
     *
     * @var array<string, array{string, string}>
     */
    private const ENUM_MIRRORING_CLASSES = [
        'App\\Util\\SponsorshipStatus' => ['sponsorships', 'status'],
    ];

    /**
     * Wertelisten, deren Spalte kein ENUM ist. Geprüft wird hier das Gegenteil:
     * dass die Spalte existiert und wirklich keines ist.
     *
     * Das ist die eigentliche Absicherung dieser Liste. Wird eine dieser
     * Spalten später per Migration zum ENUM - so wie es
     * `song_resources.resource_type` mit 20261029120000 gerade geworden ist -,
     * schlägt der Test an und verlangt den Umzug nach
     * ENUM_MIRRORING_CLASSES. Ohne diesen Teil wäre die Ausnahme eine
     * Freifahrt, die niemand je wieder anfasst.
     *
     * @var array<string, array{string, string}>
     */
    private const COLUMNS_WITHOUT_ENUM = [
        // varchar(64) an drei Tabellen (user_notification_settings,
        // user_notifications, notification_dispatch_log). Die Anlässe stehen in
        // NotificationType::DEFINITIONS samt Vorgabe und Beschriftung.
        'App\\Util\\NotificationType' => ['user_notification_settings', 'notification_type'],
        // varchar(16).
        'App\\Util\\NotificationChannel' => ['user_notification_settings', 'channel'],
    ];

    /**
     * Wertelisten ohne eigene Spalte. Geprüft wird, dass es die Spalte, die man
     * erwarten würde, tatsächlich nicht gibt.
     *
     * `SponsorEngagementState` ist der Fall: Bis 20260829121000 trug jeder
     * Sponsor einen eigenen Status in `sponsors.status`. Der lief regelmäßig
     * gegen die Vereinbarungen auseinander und ist deshalb entfallen; der
     * Zustand wird seitdem bei jedem Aufruf aus den Vereinbarungen abgeleitet.
     * Käme die Spalte zurück, wäre das eine Entscheidung, die hier auffallen
     * soll.
     *
     * @var array<string, array{string, string}>
     */
    private const DERIVED_CLASSES = [
        'App\\Util\\SponsorEngagementState' => ['sponsors', 'status'],
    ];

    protected function setUp(): void
    {
        parent::setUp();
        Bootstrap::setupTestDatabase();
    }

    /**
     * @return array<string, array{class-string, string, string}>
     */
    public static function enumMirroringProvider(): array
    {
        $cases = [];

        foreach (self::ENUM_MIRRORING_CLASSES as $class => [$table, $column]) {
            $cases[$class] = [$class, $table, $column];
        }

        return $cases;
    }

    /**
     * @param class-string $class
     */
    #[\PHPUnit\Framework\Attributes\DataProvider('enumMirroringProvider')]
    public function testDieWerteDeckenDasEnumAb(string $class, string $table, string $column): void
    {
        $allowed = self::enumValuesOf($table, $column);

        self::assertNotSame([], $allowed, sprintf(
            '%s ist als ENUM-Spiegel eingetragen, aber "%s.%s" ist kein ENUM.',
            $class,
            $table,
            $column
        ));

        /** @var list<string> $declared */
        $declared = array_map('strval', $class::all());
        sort($declared);
        sort($allowed);

        self::assertSame($allowed, $declared, sprintf(
            '%s::all() weicht von "%s.%s" ab. ENUM: %s',
            $class,
            $table,
            $column,
            implode(', ', $allowed)
        ));
    }

    /**
     * @return array<string, array{class-string, string, string}>
     */
    public static function withoutEnumProvider(): array
    {
        $cases = [];

        foreach (self::COLUMNS_WITHOUT_ENUM as $class => [$table, $column]) {
            $cases[$class] = [$class, $table, $column];
        }

        return $cases;
    }

    /**
     * @param class-string $class
     */
    #[\PHPUnit\Framework\Attributes\DataProvider('withoutEnumProvider')]
    public function testDieAusnahmeStimmtNoch(string $class, string $table, string $column): void
    {
        self::assertContains($column, self::columnsOf($table), sprintf(
            '%s nennt "%s.%s", die Spalte gibt es aber nicht.',
            $class,
            $table,
            $column
        ));

        self::assertSame([], self::enumValuesOf($table, $column), sprintf(
            '"%s.%s" ist jetzt ein ENUM. %s gehört damit nach ENUM_MIRRORING_CLASSES, '
                . 'wo die Werte gegen das ENUM geprüft werden.',
            $table,
            $column,
            $class
        ));
    }

    /**
     * @return array<string, array{class-string, string, string}>
     */
    public static function derivedProvider(): array
    {
        $cases = [];

        foreach (self::DERIVED_CLASSES as $class => [$table, $column]) {
            $cases[$class] = [$class, $table, $column];
        }

        return $cases;
    }

    /**
     * @param class-string $class
     */
    #[\PHPUnit\Framework\Attributes\DataProvider('derivedProvider')]
    public function testDerAbgeleiteteZustandHatKeineSpalte(string $class, string $table, string $column): void
    {
        self::assertNotContains($column, self::columnsOf($table), sprintf(
            '"%s.%s" gibt es wieder. %s leitet den Zustand bisher ab; wenn er jetzt '
                . 'gespeichert wird, gehört die Klasse in eine der beiden anderen Listen.',
            $table,
            $column,
            $class
        ));
    }

    /**
     * Jede Werteliste in `src/Util` steht in genau einer der drei Listen.
     *
     * Ohne diesen Teil wäre die Zuordnung oben eine Momentaufnahme: Eine neue
     * Wertklasse fiele einfach durch, und der Befund, der zu diesem Test
     * geführt hat, könnte sich an der nächsten Klasse wiederholen. Erkannt
     * werden sie an der öffentlichen statischen `all()` - das ist die Methode,
     * mit der eine solche Klasse ihre Werte herausgibt.
     */
    public function testJedeWertelisteIstZugeordnet(): void
    {
        $assigned = array_merge(
            array_keys(self::ENUM_MIRRORING_CLASSES),
            array_keys(self::COLUMNS_WITHOUT_ENUM),
            array_keys(self::DERIVED_CLASSES)
        );

        self::assertSame(
            count($assigned),
            count(array_unique($assigned)),
            'Eine Klasse steht in mehr als einer der drei Listen.'
        );

        $found = [];
        $problems = [];

        foreach (glob(dirname(__DIR__, 3) . '/src/Util/*.php') ?: [] as $file) {
            $class = 'App\\Util\\' . basename($file, '.php');

            if (!class_exists($class)) {
                continue;
            }

            $reflection = new ReflectionClass($class);
            if (!$reflection->hasMethod('all')) {
                continue;
            }

            $method = $reflection->getMethod('all');
            if (!$method->isStatic() || !$method->isPublic() || $method->getNumberOfParameters() > 0) {
                continue;
            }

            $found[] = $class;

            if (!in_array($class, $assigned, true)) {
                $problems[] = sprintf(
                    '%s gibt eine Werteliste heraus, ist aber keiner der drei Listen in %s '
                        . 'zugeordnet. Eintragen, wo sie hingehört.',
                    $class,
                    self::class
                );
            }
        }

        self::assertSame([], $problems, implode("\n", $problems));
        self::assertGreaterThanOrEqual(
            count($assigned),
            count($found),
            'Es wurden weniger Wertelisten gefunden als zugeordnet sind - die Erkennung greift nicht mehr.'
        );
    }

    /**
     * Schützt davor, dass die Prüfungen oben leer durchlaufen, weil keine
     * einzige Zuordnung mehr dasteht.
     */
    public function testEsGibtUeberhauptEinenEnumSpiegelZuPruefen(): void
    {
        self::assertNotSame([], self::ENUM_MIRRORING_CLASSES);

        foreach (array_keys(self::ENUM_MIRRORING_CLASSES) as $class) {
            self::assertTrue(
                method_exists($class, 'all'),
                sprintf('%s ist eingetragen, hat aber keine all().', $class)
            );
        }
    }

    /**
     * @return list<string>
     */
    private static function enumValuesOf(string $table, string $column): array
    {
        $type = self::columnTypeOf($table, $column);

        if (!str_starts_with($type, 'enum(')) {
            return [];
        }

        $inner = substr($type, 5, -1);
        $values = [];

        foreach (explode(',', $inner) as $value) {
            $values[] = str_replace("''", "'", trim(trim($value), "'"));
        }

        return $values;
    }

    private static function columnTypeOf(string $table, string $column): string
    {
        $row = Capsule::connection()->selectOne(
            'SELECT COLUMN_TYPE AS column_type FROM information_schema.COLUMNS '
                . 'WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND COLUMN_NAME = ?',
            [$table, $column]
        );

        return $row === null ? '' : strtolower((string) $row->column_type);
    }

    /**
     * @return list<string>
     */
    private static function columnsOf(string $table): array
    {
        $rows = Capsule::connection()->select(
            'SELECT COLUMN_NAME AS column_name FROM information_schema.COLUMNS '
                . 'WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ?',
            [$table]
        );

        return array_map(static fn (object $row): string => (string) $row->column_name, $rows);
    }
}
