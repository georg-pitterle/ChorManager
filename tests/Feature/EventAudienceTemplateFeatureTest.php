<?php

declare(strict_types=1);

namespace Tests\Feature;

use PHPUnit\Framework\TestCase;

/**
 * Termin-Formulare nutzen die gemeinsame Zielgruppen-Zeile statt eines eigenen
 * Projektfelds.
 */
class EventAudienceTemplateFeatureTest extends TestCase
{
    private static function read(string $path): string
    {
        $content = file_get_contents(dirname(__DIR__, 2) . '/templates/' . $path);
        self::assertIsString($content);

        return $content;
    }

    public function testEventFormsUseTheSharedAudienceRows(): void
    {
        foreach (['events/edit.twig', 'events/index.twig'] as $template) {
            $content = self::read($template);
            $this->assertStringContainsString('partials/audience/filter_rows.twig', $content, $template);
            $this->assertStringContainsString('prefix: "audience"', $content, $template);
        }
        $this->assertStringNotContainsString('name="project_id"', self::read('events/edit.twig'));
    }

    public function testAudienceRowOffersAllFiveCategories(): void
    {
        $row = self::read('partials/audience/filter_row.twig');
        foreach (['role', 'voice_group', 'sub_voice', 'project', 'user'] as $category) {
            $this->assertStringContainsString('category: "' . $category . '"', $row);
        }
    }

    public function testNoInlineScriptInAudiencePartials(): void
    {
        foreach (['filter_rows.twig', 'filter_row.twig', 'condition_select.twig'] as $partial) {
            $this->assertStringNotContainsString('<script', self::read('partials/audience/' . $partial), $partial);
        }
    }
}
