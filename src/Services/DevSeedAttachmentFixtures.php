<?php

declare(strict_types=1);

namespace App\Services;

/**
 * Dateiinhalte für die Seed-Anhänge.
 *
 * Vorher trugen alle Seed-Anhänge entweder `text/plain` oder ein PDF-MIME mit
 * reinem Text dahinter. Im Vorschau-Modal blieb der PDF-Rahmen damit leer, und
 * ein Bild kam in den Testdaten überhaupt nicht vor - man konnte die Vorschau in
 * Dev also nicht sehen, obwohl sie funktionierte.
 *
 * Die Inhalte sind bewusst winzig: sie sollen zeigen, dass die Anzeige greift,
 * nicht die Datenbank füllen.
 */
final class DevSeedAttachmentFixtures
{
    /**
     * Ein von Hand gebautes, minimal gültiges PDF. Kein Generator nötig - die
     * Datei besteht aus fünf Objekten und einem Trailer. Der Querverweistabelle
     * fehlen die genauen Byte-Abstände; alle gängigen Betrachter bauen den
     * Verweisbaum in diesem Fall selbst neu auf, und mehr braucht eine Testdatei
     * nicht.
     *
     * @return array{mime_type: string, extension: string, content: string}
     */
    public static function pdf(string $caption): array
    {
        // Rückstrich und Klammern beenden in PDF eine Textzeichenkette. Ein
        // Betreff wie "Beleg (Kopie)" machte die Datei sonst unlesbar.
        $escaped = str_replace(['\\', '(', ')'], ['\\\\', '\\(', '\\)'], $caption);
        $stream = 'BT /F1 18 Tf 60 700 Td (' . $escaped . ') Tj ET';

        $content = "%PDF-1.4\n"
            . "1 0 obj << /Type /Catalog /Pages 2 0 R >> endobj\n"
            . "2 0 obj << /Type /Pages /Kids [3 0 R] /Count 1 >> endobj\n"
            . "3 0 obj << /Type /Page /Parent 2 0 R /MediaBox [0 0 595 842] "
            . "/Resources << /Font << /F1 5 0 R >> >> /Contents 4 0 R >> endobj\n"
            . '4 0 obj << /Length ' . strlen($stream) . " >> stream\n"
            . $stream . "\nendstream endobj\n"
            . "5 0 obj << /Type /Font /Subtype /Type1 /BaseFont /Helvetica >> endobj\n"
            . "trailer << /Root 1 0 R /Size 6 >>\n"
            . "%%EOF\n";

        return ['mime_type' => 'application/pdf', 'extension' => 'pdf', 'content' => $content];
    }

    /**
     * Ein 1x1 Pixel großes PNG, base64-kodiert abgelegt. Kleiner geht ein
     * gültiges PNG nicht.
     *
     * @return array{mime_type: string, extension: string, content: string}
     */
    /**
     * Die Motive, die unter assets/seed/ liegen.
     *
     * @return list<string>
     */
    public static function seedImageNames(): array
    {
        return ['notenblatt', 'sponsorenlogo', 'auftrittsfoto'];
    }

    /**
     * Der Ablageort eines Motivs. Getrennt vom Namen, damit der Generator und
     * die Tests nicht je einen eigenen Pfad zusammensetzen.
     */
    public static function seedImagePath(string $name): string
    {
        return dirname(__DIR__, 2) . '/assets/seed/' . $name . '.png';
    }

    /**
     * Ein echtes Bild aus assets/seed/.
     *
     * Vorher stand hier ein 1x1-Pixel als base64: technisch ein gültiges PNG,
     * in der Vorschau aber eine leere Fläche - der Grund, warum sich die
     * Bildvorschau in Dev nicht ansehen ließ.
     *
     * Gezeichnet werden die Motive von bin/generate_seed_images.php, und zwar
     * einmalig. Der Seed liest sie nur: ein Seed-Lauf soll keine Bildbibliothek
     * voraussetzen, und die Testdaten sollen sich zwischen zwei Läufen nicht
     * unbemerkt ändern.
     *
     * Welches Motiv es wird, entscheidet der Name - fest, damit ein zweiter
     * Lauf dasselbe Bild an dieselbe Stelle legt.
     *
     * @return array{mime_type: string, extension: string, content: string}
     */
    public static function png(string $caption): array
    {
        $names = self::seedImageNames();
        $name = $names[crc32($caption) % count($names)];
        $path = self::seedImagePath($name);

        $content = is_readable($path) ? file_get_contents($path) : false;

        if ($content === false || $content === '') {
            // Absichtlich ein Abbruch statt eines Ersatzbildes: ein stiller
            // Rückfall auf einen Platzhalter war genau der Zustand, der hier
            // behoben wurde.
            throw new \RuntimeException(
                'Seed-Bild fehlt: ' . $path . ' - bitte bin/generate_seed_images.php ausführen.'
            );
        }

        return [
            'mime_type' => 'image/png',
            'extension' => 'png',
            'content'   => $content,
        ];
    }

    /**
     * @return array{mime_type: string, extension: string, content: string}
     */
    public static function text(string $body): array
    {
        return ['mime_type' => 'text/plain', 'extension' => 'txt', 'content' => $body];
    }

    /**
     * Kein echtes Office-Dokument, sondern ein Platzhalter mit dem passenden
     * MIME-Typ. Er dient genau einem Zweck: zu zeigen, dass ein nicht
     * darstellbarer Anhang in der Oberfläche keinen Vorschau-Knopf bekommt.
     *
     * @return array{mime_type: string, extension: string, content: string}
     */
    public static function wordDocument(string $caption): array
    {
        return [
            'mime_type' => 'application/vnd.openxmlformats-officedocument.wordprocessingml.document',
            'extension' => 'docx',
            'content'   => 'Platzhalter für ein Word-Dokument: ' . $caption
                . '. Dieser Anhang hat bewusst keine Vorschau.',
        ];
    }

    /**
     * Die vier Typen reihum. Damit bekommt jeder Bereich, der mehr als drei
     * Anhänge anlegt, sowohl darstellbare als auch nicht darstellbare - beide
     * Zustände der Oberfläche sind in Dev also sofort zu sehen.
     *
     * @return array{mime_type: string, extension: string, content: string}
     */
    public static function forSlot(int $slot, string $caption): array
    {
        return match ($slot % 4) {
            1 => self::pdf($caption),
            2 => self::png($caption),
            3 => self::text($caption . ' - Testinhalt als reiner Text.'),
            default => self::wordDocument($caption),
        };
    }
}
