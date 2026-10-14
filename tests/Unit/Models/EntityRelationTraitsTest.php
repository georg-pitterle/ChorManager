<?php

declare(strict_types=1);

namespace Tests\Unit\Models;

use App\Models\Attachment;
use App\Models\Comment;
use App\Models\Concerns\HasEntityAttachments;
use App\Models\Concerns\HasEntityComments;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasOneOrMany;
use Illuminate\Database\Eloquent\Relations\Relation;
use PHPUnit\Framework\TestCase;
use ReflectionClass;
use ReflectionMethod;
use Tests\Unit\Bootstrap;

/**
 * Notizen und Anhänge hängen über `entity_type`/`entity_id` an einer Entität.
 * Diese Beziehung wird einmal definiert, nicht je Modell abgeschrieben.
 *
 * Vorher stand `comments()` bei Termin, Projekt und Aufgabe dreimal wortgleich
 * da - bis auf den entity_type-Text - und `attachments()` sechsmal: Projekt,
 * Aufgabe, Lied, Sponsor, Vereinbarung, Buchung. Wer die Sortierung oder die
 * Grenze um die privaten Notizen anfasste, musste alle Stellen treffen; genau so
 * fehlte die Sortierung bei Aufgabe und Projekt eine Zeit lang ganz, während der
 * Termin sie längst hatte. Dieselbe Begründung trägt bereits
 * HasAudienceConditionSets.
 *
 * `User::comments()` und `User::activities()` sind etwas anderes und bleiben
 * ausgenommen: Sie hängen über `user_id` an der schreibenden Person, nicht über
 * `entity_id` an einer Entität. Der Test unterscheidet sie am Fremdschlüssel,
 * nicht am Methodennamen.
 */
final class EntityRelationTraitsTest extends TestCase
{
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
    public function testKeinModellSchreibtDieseBeziehungSelbst(string $class): void
    {
        $problems = [];

        foreach ((new ReflectionClass($class))->getMethods(ReflectionMethod::IS_PUBLIC) as $method) {
            if ($method->getNumberOfParameters() > 0 || $method->isStatic()) {
                continue;
            }

            // Nur was die Klasse selbst im Rumpf stehen hat. Eine Methode aus
            // einer Trait meldet als deklarierende Klasse ebenfalls die Klasse,
            // deshalb wird zusätzlich gegen die Traits abgeglichen.
            if ($method->getDeclaringClass()->getName() !== $class) {
                continue;
            }

            if ($this->comesFromTrait($class, $method->getName())) {
                continue;
            }

            $relation = $this->relationOf($class, $method->getName());
            if (!$relation instanceof HasOneOrMany) {
                continue;
            }

            $related = $relation->getRelated()::class;
            if ($related !== Comment::class && $related !== Attachment::class) {
                continue;
            }

            $foreignKey = (string) array_slice(explode('.', $relation->getQualifiedForeignKeyName()), -1)[0];
            if ($foreignKey !== 'entity_id') {
                continue;
            }

            $problems[] = sprintf(
                '%s::%s() definiert die Beziehung über entity_id selbst. Sie gehört in '
                    . 'HasEntityComments bzw. HasEntityAttachments.',
                $class,
                $method->getName()
            );
        }

        self::assertSame([], $problems, implode("\n", $problems));
    }

    /**
     * @param class-string<Model> $class
     */
    #[\PHPUnit\Framework\Attributes\DataProvider('modelProvider')]
    public function testWerDieTraitsNutztBenenntSeinenTyp(string $class): void
    {
        $traits = class_uses($class) ?: [];
        $usesTrait = isset($traits[HasEntityComments::class]) || isset($traits[HasEntityAttachments::class]);

        if (!$usesTrait) {
            self::assertTrue(true);

            return;
        }

        $reflection = new ReflectionClass($class);

        self::assertTrue(
            $reflection->hasConstant('ENTITY_TYPE'),
            sprintf('%s nutzt eine dieser Beziehungen, benennt aber kein ENTITY_TYPE.', $class)
        );

        $entityType = $reflection->getConstant('ENTITY_TYPE');

        self::assertIsString($entityType, sprintf('%s::ENTITY_TYPE muss ein Text sein.', $class));
        self::assertNotSame('', $entityType, sprintf('%s::ENTITY_TYPE ist leer.', $class));
    }

    /**
     * Die Traits sollen nicht leer durchlaufen, weil sie niemand mehr nutzt.
     */
    public function testDieTraitsWerdenAuchGenutzt(): void
    {
        $withComments = 0;
        $withAttachments = 0;

        foreach (self::modelProvider() as [$class]) {
            $traits = class_uses($class) ?: [];
            $withComments += isset($traits[HasEntityComments::class]) ? 1 : 0;
            $withAttachments += isset($traits[HasEntityAttachments::class]) ? 1 : 0;
        }

        self::assertGreaterThanOrEqual(3, $withComments, 'Die Notiz-Trait wird kaum genutzt.');
        self::assertGreaterThanOrEqual(6, $withAttachments, 'Die Anhang-Trait wird kaum genutzt.');
    }

    /**
     * @param class-string<Model> $class
     */
    private function comesFromTrait(string $class, string $method): bool
    {
        foreach (class_uses($class) ?: [] as $trait) {
            if (method_exists($trait, $method)) {
                return true;
            }
        }

        return false;
    }

    /**
     * @param class-string<Model> $class
     */
    private function relationOf(string $class, string $method): ?Relation
    {
        try {
            $relation = (new $class())->{$method}();
        } catch (\Throwable) {
            return null;
        }

        return $relation instanceof Relation ? $relation : null;
    }
}
