<?php

declare(strict_types=1);

namespace Tests\Feature;

use PHPUnit\Framework\TestCase;

/**
 * TinyMCE ist mit rund 480 KB das größte Skript der Oberfläche. Es wird nur auf Seiten
 * geladen, die einen Editor enthalten; alles andere erledigt der Nachlader in tinymce-init.js.
 */
class EditorLoadingFeatureTest extends TestCase
{
    private const EDITOR_SCRIPT = '/vendor/tinymce/tinymce/tinymce.min.js';

    private static function root(): string
    {
        return dirname(__DIR__, 2);
    }

    private static function read(string $relativePath): string
    {
        $content = file_get_contents(self::root() . '/' . $relativePath);
        self::assertIsString($content, $relativePath);

        return $content;
    }

    private static function loadsEditor(string $template): bool
    {
        return str_contains($template, '{% block editor_scripts %}') && str_contains($template, self::EDITOR_SCRIPT);
    }

    public function testLayoutLoadsEditorOnlyWhenPageAsksForIt(): void
    {
        $layout = self::read('templates/layout.twig');

        $this->assertStringContainsString('{% block editor_scripts %}', $layout);
        $this->assertStringNotContainsString(
            self::EDITOR_SCRIPT,
            $layout,
            'Das Layout lädt den Editor ohne Bedingung, statt ihn den Seiten zu überlassen.'
        );
    }

    public function testEveryTemplateWithAnEditorAsksForIt(): void
    {
        $missing = [];
        $found = 0;

        $iterator = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator(self::root() . '/templates', \FilesystemIterator::SKIP_DOTS)
        );

        foreach ($iterator as $file) {
            if (!$file instanceof \SplFileInfo || $file->getExtension() !== 'twig') {
                continue;
            }

            $content = (string) file_get_contents($file->getPathname());
            if (!str_contains($content, 'tinymce-editor')) {
                continue;
            }

            $found++;
            if (!self::loadsEditor($content)) {
                $missing[] = substr($file->getPathname(), strlen(self::root()) + 1);
            }
        }

        $this->assertGreaterThanOrEqual(6, $found);
        $this->assertSame([], $missing, 'Seiten mit Editor füllen den Block editor_scripts nicht.');
    }

    public function testNewsletterPagesThatOpenEditorDialogsAskForIt(): void
    {
        foreach (['index', 'archive', 'locked'] as $page) {
            $this->assertTrue(
                self::loadsEditor(self::read('templates/newsletters/' . $page . '.twig')),
                'newsletters/' . $page . '.twig lädt Editor-Dialoge nach, braucht den Editor aber sofort.'
            );
        }
    }

    public function testInitScriptLoadsEditorOnDemand(): void
    {
        $script = self::read('public/js/tinymce-init.js');

        $this->assertStringContainsString("document.createElement('script')", $script);
        $this->assertStringContainsString(self::EDITOR_SCRIPT, $script);
        $this->assertStringContainsString('loadTinymce()', $script);
    }
}
