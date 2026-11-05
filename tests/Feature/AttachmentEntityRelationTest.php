<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\Attachment;
use App\Models\Finance;
use App\Models\Project;
use App\Models\Song;
use App\Models\Sponsor;
use App\Util\EntityType;
use Illuminate\Database\Capsule\Manager as Capsule;
use Illuminate\Database\Eloquent\Relations\Relation;
use PHPUnit\Framework\TestCase;
use Tests\Unit\Bootstrap;

/**
 * Anhänge hängen polymorph an `entity_type`/`entity_id`, und die Rückrichtung
 * muss die Entität auch stapelweise auflösen.
 *
 * Bis hierher stand dort `Attachment::song()`: eine belongsTo auf Lieder, die
 * sich mit `whereRaw('1 = 0')` selbst abschaltete, wenn die Zeile kein Lied
 * meinte. Der Einzelzugriff funktionierte, Eager Loading nicht - Eloquent baut
 * die Eager-Bedingung aus einer leeren Modellinstanz, deren `entity_type` null
 * ist, und die Relation blieb für jede Zeile leer. Wer viele Anhänge mit ihrer
 * Entität brauchte, musste vorher auf `entity_type` filtern und die Entitäten
 * selbst nachholen - und für alles außer Liedern gab es die Rückrichtung
 * überhaupt nicht.
 *
 * Jetzt ist es ein echtes `morphTo` über die Morph-Map aus `App\Util\EntityType`.
 * Eloquent gruppiert die Zeilen nach `entity_type`, löst jeden Namen über die
 * Map in seine Klasse auf und holt je Tabelle eine Abfrage - eine gemischte
 * Liste kostet damit so viele Abfragen, wie Entitätsarten darin vorkommen, und
 * nicht eine je Zeile.
 */
final class AttachmentEntityRelationTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        Bootstrap::setupTestDatabase();
        Capsule::connection()->beginTransaction();
    }

    protected function tearDown(): void
    {
        Capsule::connection()->rollBack();
        parent::tearDown();
    }

    public function testDerEinzelzugriffLoestDieEntitaetAuf(): void
    {
        $song = Song::create(['title' => 'Anhang-Lied ' . bin2hex(random_bytes(4))]);
        $attachment = $this->attachment(Song::ENTITY_TYPE, (int) $song->id);

        $resolved = $attachment->entity;

        self::assertInstanceOf(Song::class, $resolved);
        self::assertSame((int) $song->id, (int) $resolved->id);
    }

    /**
     * Der Befund, der die Umstellung ausgelöst hat: Vorher kam hier `null`.
     */
    public function testEagerLoadingLoestDieEntitaetAuf(): void
    {
        $song = Song::create(['title' => 'Eager-Lied ' . bin2hex(random_bytes(4))]);
        $created = $this->attachment(Song::ENTITY_TYPE, (int) $song->id);

        $loaded = Attachment::query()->with('entity')->find($created->id);

        self::assertNotNull($loaded);
        self::assertTrue($loaded->relationLoaded('entity'), 'Die Beziehung wurde nicht vorab geladen.');
        self::assertInstanceOf(Song::class, $loaded->entity);
        self::assertSame((int) $song->id, (int) $loaded->entity->id);
    }

    /**
     * Eine gemischte Liste in einem Zug: Genau das konnte die alte Relation
     * nicht, und genau das braucht eine Übersicht über alle Anhänge.
     */
    public function testEagerLoadingLoestMehrereEntitaetsartenGemischtAuf(): void
    {
        $song = Song::create(['title' => 'Misch-Lied ' . bin2hex(random_bytes(4))]);
        $project = Project::create(['name' => 'Misch-Projekt ' . bin2hex(random_bytes(4))]);
        $sponsor = Sponsor::create([
            'name' => 'Misch-Sponsor ' . bin2hex(random_bytes(4)),
            'type' => Sponsor::TYPE_ORGANIZATION,
        ]);

        $ids = [
            Song::class => $this->attachment(Song::ENTITY_TYPE, (int) $song->id)->id,
            Project::class => $this->attachment(Project::ENTITY_TYPE, (int) $project->id)->id,
            // Der Sponsor führt seine Logos unter einem eigenen Namen, zeigt aber
            // auf dieselbe Tabelle - die Map kennt beide Namen.
            Sponsor::class => $this->attachment(Sponsor::LOGO_ENTITY_TYPE, (int) $sponsor->id)->id,
        ];

        $loaded = Attachment::query()
            ->with('entity')
            ->whereIn('id', array_values($ids))
            ->get()
            ->keyBy('id');

        foreach ($ids as $expectedClass => $attachmentId) {
            self::assertInstanceOf(
                $expectedClass,
                $loaded[$attachmentId]->entity,
                sprintf('Anhang %d wurde nicht als %s aufgelöst.', $attachmentId, $expectedClass)
            );
        }
    }

    /**
     * Eine Zeile, deren Entität es nicht mehr gibt, liefert `null` statt einen
     * Fehler - die Anhänge stehen ohne Fremdschlüssel da, es kann sie geben.
     */
    public function testEineVerwaisteZeileLiefertNull(): void
    {
        $attachment = $this->attachment(Finance::ENTITY_TYPE, 2147483600);

        self::assertNull($attachment->entity);
    }

    /**
     * Die Map muss jeden `entity_type` kennen, den die Modelle führen. Sonst
     * wirft `morphTo` für eine neue Entitätsart eine Ausnahme über eine Klasse,
     * die es nicht gibt - und das fiele erst im Betrieb auf.
     */
    public function testDieMorphMapKenntJedenEntityTypeDerModelle(): void
    {
        Attachment::query()->getModel();

        $missing = [];

        foreach (EntityType::map() as $alias => $class) {
            if (Relation::getMorphedModel($alias) !== $class) {
                $missing[] = $alias;
            }
        }

        self::assertSame([], $missing, 'Nicht registriert: ' . implode(', ', $missing));
    }

    private function attachment(string $entityType, int $entityId): Attachment
    {
        return Attachment::create([
            'entity_type' => $entityType,
            'entity_id' => $entityId,
            'filename' => 'noten.pdf',
            'original_name' => 'noten.pdf',
            'mime_type' => 'application/pdf',
            'file_size' => 3,
            'file_content' => 'pdf',
        ]);
    }
}
