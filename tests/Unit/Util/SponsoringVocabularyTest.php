<?php

declare(strict_types=1);

namespace Tests\Unit\Util;

use PHPUnit\Framework\TestCase;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;

/**
 * In der Oberfläche heißt der Sponsoring-Fall "Vorgang", nicht "Vereinbarung".
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
