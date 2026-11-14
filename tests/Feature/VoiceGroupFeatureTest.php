<?php

declare(strict_types=1);

namespace Tests\Feature;

use PHPUnit\Framework\TestCase;

class VoiceGroupFeatureTest extends TestCase
{
    public function testVoiceGroupStructureExists(): void
    {
        $routesContent = file_get_contents(dirname(__DIR__) . '/../src/Routes.php');
        $this->assertIsString($routesContent);
        $this->assertStringContainsString("'/voice-groups'", $routesContent);
        $this->assertStringContainsString("'/voice-groups/{id:[0-9]+}/sub'", $routesContent);

        $this->assertTrue(file_exists(dirname(__DIR__) . '/../templates/voice_groups/index.twig'));
    }
}
