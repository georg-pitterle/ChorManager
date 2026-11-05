<?php

declare(strict_types=1);

namespace App\Util;

use App\Models\Event;
use App\Models\Finance;
use App\Models\Newsletter;
use App\Models\Project;
use App\Models\Song;
use App\Models\Sponsor;
use App\Models\Sponsorship;
use App\Models\Task;
use Illuminate\Database\Eloquent\Relations\Relation;

/**
 * Einzige Quelle dafür, welches Modell hinter einem `entity_type` steht.
 *
 * Anhänge, Notizen und Verlaufseinträge hängen nicht per Fremdschlüssel an
 * ihrer Entität, sondern über das Paar `entity_type`/`entity_id`. Die Hinrichtung
 * kennt ihren Namen von selbst - jedes Modell trägt ihn als `ENTITY_TYPE`
 * (Concerns\HasEntityAttachments, Concerns\HasEntityComments). Für die
 * Rückrichtung fehlte die Übersetzung in die andere Richtung, und sie stand
 * deshalb nirgends: `Attachment::song()` war eine belongsTo auf Lieder, die sich
 * mit `whereRaw('1 = 0')` selbst abschaltete, wenn die Zeile etwas anderes
 * meinte - für alle übrigen Entitätsarten gab es die Rückrichtung gar nicht, und
 * Eager Loading trug auch für Lieder nicht.
 *
 * Die Namen werden hier nicht abgeschrieben, sondern aus den Konstanten der
 * Modelle gelesen. Damit gibt es den Wert weiterhin genau einmal, und ein
 * fehlender Eintrag fällt in Tests\Unit\Util\EntityTypeMapTest auf.
 *
 * Der Sponsor steht zweimal darin: Seine Logo-Dateien laufen unter einem eigenen
 * Namen (`sponsor_logo`, siehe Concerns\HasLogoAttachments), zeigen aber auf
 * dieselbe Tabelle. Eloquent erlaubt zwei Namen für dieselbe Klasse, solange nur
 * die Rückrichtung über die Map geht - und das ist hier der Fall. Die
 * Hinrichtung nennt ihren `entity_type` überall ausgeschrieben und fragt die Map
 * nie.
 */
final class EntityType
{
    /**
     * Name aus `entity_type` => Modellklasse.
     *
     * @return array<string, class-string<\Illuminate\Database\Eloquent\Model>>
     */
    public static function map(): array
    {
        return [
            Event::ENTITY_TYPE => Event::class,
            Finance::ENTITY_TYPE => Finance::class,
            Newsletter::ENTITY_TYPE => Newsletter::class,
            Project::ENTITY_TYPE => Project::class,
            Song::ENTITY_TYPE => Song::class,
            Sponsor::ENTITY_TYPE => Sponsor::class,
            Sponsor::LOGO_ENTITY_TYPE => Sponsor::class,
            Sponsorship::ENTITY_TYPE => Sponsorship::class,
            Task::ENTITY_TYPE => Task::class,
        ];
    }

    /**
     * Meldet die Map bei Eloquent an, damit `morphTo` einen Namen aus der Spalte
     * in seine Klasse auflösen kann.
     *
     * Aufgerufen wird das aus `Attachment::booted()` und nicht aus
     * `src/Dependencies.php`: Die Kommandozeilen-Befehle und der Testlauf bauen
     * ihre Datenbankverbindung selbst auf (`bin/`, `tests/Unit/Bootstrap`) und
     * kommen am Container vorbei. Eine Anmeldung dort hätte für die Anwendung
     * gegolten und für beides nicht - und ein `morphTo` ohne Map hält den Namen
     * aus der Spalte für einen Klassennamen.
     *
     * `morphMap()` und nicht `enforceMorphMap()`: Letzteres verlangt für *jede*
     * polymorphe Beziehung einen Eintrag. Das wäre eine Zusage über Beziehungen,
     * die es hier noch nicht gibt.
     */
    public static function register(): void
    {
        Relation::morphMap(self::map());
    }
}
