<?php

declare(strict_types=1);

namespace Tests\Unit\Models;

use App\Models\Task;
use PHPUnit\Framework\TestCase;

/**
 * Zustand und Dringlichkeit einer Aufgabe tragen englische Bezeichner, und zu
 * jedem Wert gehört eine vollständige deutsche Darstellung.
 *
 * Bis Lauf 51 hielten `tasks.status` und `tasks.priority` deutsche Werte
 * ('Offen', 'In Bearbeitung', 'Abgeschlossen', 'Blockiert' / 'Niedrig',
 * 'Mittel', 'Hoch') - entgegen `instructions/naming.md`, nach dem ein im Code
 * adressierter Datenbankwert ein englischer Bezeichner ist. Der Wert
 * 'In Bearbeitung' ging mit seinem Leerzeichen zusätzlich als
 * `data-drop-zone` durch die Vorlage und das Kanban-JavaScript.
 *
 * Dass die Werte zum ENUM der Spalte passen, hält ModelSchemaConsistencyTest
 * fest. Hier steht das, was daneben gilt: dass sie Bezeichner sind und dass
 * keine der Zuordnungen für die Oberfläche einen Wert vergisst.
 */
final class TaskStatusValuesTest extends TestCase
{
    /**
     * Jede Zuordnung, die eine Darstellung je Zustand liefert, mit ihrem Namen
     * für die Fehlermeldung.
     *
     * @return array<string, array<string, string>>
     */
    private static function statusMaps(): array
    {
        return [
            'STATUS_LABELS' => Task::STATUS_LABELS,
            'STATUS_BADGE_CLASSES' => Task::STATUS_BADGE_CLASSES,
            'STATUS_ICONS' => Task::STATUS_ICONS,
            'STATUS_ICON_COLORS' => Task::STATUS_ICON_COLORS,
            'STATUS_EMPTY_TEXTS' => Task::STATUS_EMPTY_TEXTS,
        ];
    }

    /**
     * @return array<string, array<string, string>>
     */
    private static function priorityMaps(): array
    {
        return [
            'PRIORITY_LABELS' => Task::PRIORITY_LABELS,
            'PRIORITY_BADGE_CLASSES' => Task::PRIORITY_BADGE_CLASSES,
        ];
    }

    /**
     * Die Werte sind englische Bezeichner: Kleinbuchstaben, Unterstrich als
     * Trenner, kein Leerzeichen, keine Umlaute.
     *
     * Das Leerzeichen ist der Punkt, an dem es vorher wehtat: Der alte Wert
     * 'In Bearbeitung' stand als `data-drop-zone` im HTML und lief von dort
     * durch das JavaScript zurück über die Statusroute.
     */
    public function testZustaendeUndDringlichkeitenSindEnglischeBezeichner(): void
    {
        foreach ([...Task::STATUSES, ...Task::PRIORITIES] as $value) {
            self::assertMatchesRegularExpression(
                '/^[a-z][a-z0-9_]*$/',
                $value,
                sprintf('"%s" ist kein englischer Bezeichner in Kleinschreibung.', $value)
            );
        }
    }

    /**
     * Keine Zuordnung für die Oberfläche darf einen Zustand übergehen.
     *
     * Genau das war vorher der Fall: Die `if/elseif`-Kette der Listenansicht in
     * `projects/tasks.twig` kannte 'Blockiert' nicht, ihr `{% else %}` fing den
     * Zustand mit "offen" zusammen ab - eine blockierte Aufgabe stand in der
     * Liste als "Offen" da, während die Kanban-Ansicht daneben ihre eigene
     * Spalte dafür hatte.
     */
    public function testJedeDarstellungKenntJedenZustand(): void
    {
        foreach (self::statusMaps() as $name => $map) {
            self::assertSame(
                Task::STATUSES,
                array_keys($map),
                sprintf('Task::%s deckt nicht genau Task::STATUSES ab.', $name)
            );
        }

        foreach (self::priorityMaps() as $name => $map) {
            self::assertSame(
                Task::PRIORITIES,
                array_keys($map),
                sprintf('Task::%s deckt nicht genau Task::PRIORITIES ab.', $name)
            );
        }
    }

    /**
     * Die Oberfläche bleibt deutsch: Jeder Wert trägt ein Label, und das Label
     * ist nicht der Bezeichner selbst.
     */
    public function testJederWertHatEinDeutschesLabel(): void
    {
        $labelMaps = [
            'STATUS_LABELS' => Task::STATUS_LABELS,
            'PRIORITY_LABELS' => Task::PRIORITY_LABELS,
        ];

        foreach ($labelMaps as $name => $map) {
            foreach ($map as $value => $label) {
                self::assertNotSame('', trim($label), sprintf('Task::%s["%s"] ist leer.', $name, $value));
                self::assertNotSame(
                    $value,
                    $label,
                    sprintf('Task::%s["%s"] trägt den Bezeichner als Label - das ist kein deutscher Text.', $name, $value)
                );
            }
        }
    }

    /**
     * Der Hinweis in einer leeren Spalte ist deutscher Text und nennt den
     * Zustand nicht als Bezeichner.
     */
    public function testDerHinweisInEinerLeerenSpalteIstDeutsch(): void
    {
        foreach (Task::STATUS_EMPTY_TEXTS as $status => $text) {
            self::assertStringStartsWith('Keine ', $text, sprintf('Hinweis für "%s" passt nicht.', $status));
            self::assertStringNotContainsString($status, $text);
        }
    }
}
