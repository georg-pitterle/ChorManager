<?php

declare(strict_types=1);

namespace Tests\Unit\Util;

use PHPUnit\Framework\TestCase;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;

/**
 * In der Oberfläche heißt der Sponsoring-Fall "Anfrage" - nicht "Vereinbarung"
 * und nicht mehr "Vorgang".
 *
 * "Vereinbarung" klang nach dem unterschriebenen Vertrag, gemeint ist aber der
 * ganze Weg von der ersten Anfrage bis zum Abschluss - auch einer, der mit einer
 * Absage endet. Der Wächter prüft nur, was jemand zu lesen bekommt: Templates ohne
 * Kommentare, Zeichenketten in src/ und die Hilfe. Code-Kommentare und
 * Bezeichner (`Sponsorship`, `agreement_*`) sind nicht betroffen.
 */
final class SponsoringVocabularyTest extends TestCase
{
    private const OLD_TERM = 'Vereinbarung';

    public function testNoTemplateShowsTheOldTerm(): void
    {
        foreach ($this->files('templates', 'twig') as $path) {
            $text = preg_replace('/\{#.*?#\}/su', '', (string) file_get_contents($path));

            $this->assertStringNotContainsString(self::OLD_TERM, (string) $text, $path);
        }
    }

    public function testNoStringInTheApplicationCodeShowsTheOldTerm(): void
    {
        foreach ($this->files('src', 'php') as $path) {
            foreach (file($path) ?: [] as $number => $line) {
                $code = ltrim($line);
                if (str_starts_with($code, '*') || str_starts_with($code, '/*') || str_starts_with($code, '//')) {
                    continue;
                }

                $this->assertStringNotContainsString(self::OLD_TERM, $line, $path . ':' . ($number + 1));
            }
        }
    }

    public function testTheHelpDoesNotUseTheOldTerm(): void
    {
        foreach ($this->files('help', 'md') as $path) {
            $this->assertStringNotContainsString(self::OLD_TERM, (string) file_get_contents($path), $path);
        }
    }

    /**
     * Seit der Rückmeldung aus dem ersten Praxistest (November 2026) heißt der
     * Sponsoring-Fall "Anfrage", die "Wiedervorlage" heißt "Erinnerung", der
     * Status "Erinnert" heißt "Nachgefragt", und es wird "nachgefragt" statt
     * "nachgefasst". "Vorgang" war nicht selbsterklärend, und "Erinnert" stand
     * neben der neuen "Erinnerung" für etwas anderes.
     *
     * "Wiedervorlage" gab es nur im Sponsoring und ist deshalb überall
     * gesperrt. "Vorgang" ist ein gewöhnliches Wort (Backup, Löschhinweise) und
     * nur dort gesperrt, wo es den Sponsoring-Fall meinte.
     */
    private const RETIRED_EVERYWHERE = '/Wiedervorlage/u';
    private const RETIRED_IN_SPONSORING = '/Vorg(ang|äng)|Wiedervorlage|Erinnert|achfass|nachgefasst/u';

    public function testNoTemplateShowsARetiredTerm(): void
    {
        foreach ($this->files('templates', 'twig') as $path) {
            $text = (string) preg_replace('/\{#.*?#\}/su', '', (string) file_get_contents($path));
            $pattern = $this->isSponsoringFile($path) ? self::RETIRED_IN_SPONSORING : self::RETIRED_EVERYWHERE;

            $this->assertSame(0, preg_match($pattern, $text), $path);
        }
    }

    public function testNoStringInTheApplicationCodeShowsARetiredTerm(): void
    {
        foreach ($this->files('src', 'php') as $path) {
            $pattern = $this->isSponsoringFile($path) ? self::RETIRED_IN_SPONSORING : self::RETIRED_EVERYWHERE;

            foreach (file($path) ?: [] as $number => $line) {
                $code = ltrim($line);
                if (str_starts_with($code, '*') || str_starts_with($code, '/*') || str_starts_with($code, '//')) {
                    continue;
                }

                $this->assertSame(0, preg_match($pattern, $line), $path . ':' . ($number + 1));
            }
        }
    }

    public function testTheHelpDoesNotUseARetiredTerm(): void
    {
        foreach ($this->files('help', 'md') as $path) {
            $pattern = $this->isSponsoringFile($path) ? self::RETIRED_IN_SPONSORING : self::RETIRED_EVERYWHERE;

            $this->assertSame(0, preg_match($pattern, (string) file_get_contents($path)), $path);
        }
    }

    /**
     * Dateien, in denen "Vorgang" nur den Sponsoring-Fall meinen kann: der
     * Ordner oder der Dateiname trägt "sponsor".
     */
    private function isSponsoringFile(string $path): bool
    {
        return stripos(str_replace('\\', '/', $path), 'sponsor') !== false;
    }

    /**
     * @return list<string>
     */
    private function files(string $directory, string $extension): array
    {
        $root = dirname(__DIR__, 3) . '/' . $directory;
        $paths = [];
        foreach (new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root)) as $file) {
            if ($file->isFile() && $file->getExtension() === $extension) {
                $paths[] = $file->getPathname();
            }
        }

        return $paths;
    }
}
