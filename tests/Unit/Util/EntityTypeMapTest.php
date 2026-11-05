<?php

declare(strict_types=1);

namespace Tests\Unit\Util;

use App\Util\EntityType;
use Illuminate\Database\Eloquent\Model;
use PHPUnit\Framework\TestCase;
use ReflectionClass;

/**
 * Die Morph-Map muss jeden `entity_type` kennen, den irgendein Modell führt.
 *
 * `Attachment::entity()` löst den Namen aus der Spalte über diese Map in eine
 * Klasse auf. Fehlt ein Name, wirft Eloquent erst beim Lesen einer solchen
 * Zeile - und zwar über eine Klasse, die es nicht gibt. Eine neue Entitätsart
 * entsteht aber an ihrem Modell (`const ENTITY_TYPE`) und nicht hier; ohne
 * diesen Wächter bliebe das Nachtragen Gedächtnissache.
 *
 * Gelesen werden die Konstanten der Modelle, nicht eine zweite Aufzählung:
 * Die Liste, gegen die geprüft wird, kann damit nicht selbst veralten.
 */
final class EntityTypeMapTest extends TestCase
{
    public function testJedeEntityTypeKonstanteStehtInDerMap(): void
    {
        $map = EntityType::map();
        $problems = [];
        $found = 0;

        foreach (self::modelClasses() as $class) {
            $reflection = new ReflectionClass($class);

            foreach (['ENTITY_TYPE', 'LOGO_ENTITY_TYPE'] as $name) {
                if (!$reflection->hasConstant($name)) {
                    continue;
                }

                $found++;
                $alias = (string) $reflection->getConstant($name);

                if (!array_key_exists($alias, $map)) {
                    $problems[] = sprintf('%s::%s ("%s") fehlt in EntityType::map().', $class, $name, $alias);
                    continue;
                }

                if ($map[$alias] !== $class) {
                    $problems[] = sprintf(
                        'EntityType::map() führt "%s" auf %s, die Konstante steht aber auf %s.',
                        $alias,
                        $map[$alias],
                        $class
                    );
                }
            }
        }

        self::assertSame([], $problems, implode("\n", $problems));
        self::assertGreaterThanOrEqual(8, $found, 'Es wurde kaum eine ENTITY_TYPE-Konstante gefunden.');
    }

    /**
     * Umgekehrt: Kein Name in der Map ohne Modell, das ihn führt. Sonst bleibt
     * eine Zuordnung stehen, deren Entitätsart es längst nicht mehr gibt.
     */
    public function testDieMapNenntKeinenUnbekanntenNamen(): void
    {
        $declared = [];

        foreach (self::modelClasses() as $class) {
            $reflection = new ReflectionClass($class);

            foreach (['ENTITY_TYPE', 'LOGO_ENTITY_TYPE'] as $name) {
                if ($reflection->hasConstant($name)) {
                    $declared[(string) $reflection->getConstant($name)] = $class;
                }
            }
        }

        $unknown = array_keys(array_diff_key(EntityType::map(), $declared));

        self::assertSame([], $unknown, 'Ohne Modell-Konstante: ' . implode(', ', $unknown));
    }

    /**
     * @return list<class-string<Model>>
     */
    private static function modelClasses(): array
    {
        $classes = [];

        foreach (glob(dirname(__DIR__, 3) . '/src/Models/*.php') ?: [] as $file) {
            $class = 'App\\Models\\' . basename($file, '.php');

            if (class_exists($class) && is_subclass_of($class, Model::class)) {
                $classes[] = $class;
            }
        }

        return $classes;
    }
}
