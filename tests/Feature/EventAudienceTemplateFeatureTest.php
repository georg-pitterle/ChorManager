<?php

declare(strict_types=1);

namespace Tests\Feature;

use PHPUnit\Framework\TestCase;

class EventAudienceTemplateFeatureTest extends TestCase
{
    public function testEditTemplateUsesAudiencePartialNotProjectSelect(): void
    {
        $edit = file_get_contents(dirname(__DIR__, 2) . '/templates/events/edit.twig');
        $this->assertIsString($edit);
        $this->assertStringContainsString('events/_audience_sources.twig', $edit);
        $this->assertStringNotContainsString('name="project_id"', $edit);
    }

    public function testAudiencePartialHasAllFourSourceTypes(): void
    {
        $partial = file_get_contents(dirname(__DIR__, 2) . '/templates/events/_audience_sources.twig');
        $this->assertIsString($partial);
        foreach (['project_members', 'role', 'voice_group', 'user'] as $type) {
            $this->assertStringContainsString('data-source-type="' . $type . '"', $partial);
        }
    }

    public function testNoInlineScriptInPartial(): void
    {
        $partial = file_get_contents(dirname(__DIR__, 2) . '/templates/events/_audience_sources.twig');
        $this->assertStringNotContainsString('<script', $partial);
    }
}
