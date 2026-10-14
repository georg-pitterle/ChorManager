<?php

declare(strict_types=1);

namespace Tests\Unit\Models;

use PHPUnit\Framework\TestCase;

/**
 * Ein Modell beschreibt Daten und Beziehungen. Es ruft keine Dienste.
 *
 * `Event::eligibleUsersQuery()` hat sich den AudienceFilterService selbst gebaut
 * (`new AudienceFilterService()`) und damit die Richtung umgedreht: Die
 * Datenschicht griff in die Dienstschicht, an der Dependency Injection vorbei.
 * Wer den Dienst ersetzen oder im Test vorgeben wollte, kam an dieser Stelle
 * nicht heran, und das Modell trug Wissen über die Auflösung von Zielgruppen,
 * das nirgends sonst in `src/Models` steht.
 *
 * Die Frage stellt sich an jedem neuen Modell wieder, deshalb steht sie hier als
 * Regel und nicht als Einzelfall-Prüfung an Event. Gelesen wird die Quelle, weil
 * genau die Schreibweise gemeint ist - eine Reflexion sähe den Aufruf im
 * Methodenrumpf nicht.
 */
final class ModelsStayDataLayerTest extends TestCase
{
    public function testKeinModellRuftEinenDienst(): void
    {
        $files = glob(dirname(__DIR__, 3) . '/src/Models/*.php') ?: [];
        $files = array_merge($files, glob(dirname(__DIR__, 3) . '/src/Models/Concerns/*.php') ?: []);

        self::assertGreaterThan(50, count($files), 'Es wurden kaum Modelle gefunden.');

        $problems = [];

        foreach ($files as $file) {
            $source = (string) file_get_contents($file);
            $name = basename($file);

            if (preg_match('/^use\s+App\\\\Services\\\\/m', $source) === 1) {
                $problems[] = $name . ': importiert etwas aus App\\Services.';
            }

            if (preg_match('/new\s+\\\\?(App\\\\Services\\\\)?\w*Service\s*\(/', $source) === 1) {
                $problems[] = $name . ': baut sich einen Dienst mit new.';
            }
        }

        self::assertSame([], $problems, implode("\n", $problems));
    }
}
