<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Controllers\VoiceGroupController;
use PHPUnit\Framework\TestCase;

class VoiceGroupFeatureTest extends TestCase
{
    public function testVoiceGroupStructureExists(): void
    {
        $this->assertTrue(class_exists(VoiceGroupController::class));
        $this->assertTrue(method_exists(VoiceGroupController::class, 'index'));
        $this->assertTrue(method_exists(VoiceGroupController::class, 'createGroup'));
        $this->assertTrue(method_exists(VoiceGroupController::class, 'updateGroup'));
        $this->assertTrue(method_exists(VoiceGroupController::class, 'deleteGroup'));
        $this->assertTrue(method_exists(VoiceGroupController::class, 'createSubVoice'));
        $this->assertTrue(method_exists(VoiceGroupController::class, 'updateSubVoice'));
        $this->assertTrue(method_exists(VoiceGroupController::class, 'deleteSubVoice'));

        $routesContent = file_get_contents(dirname(__DIR__) . '/../src/Routes.php');
        $this->assertIsString($routesContent);
        $this->assertStringContainsString("'/voice-groups'", $routesContent);
        $this->assertStringContainsString("'/voice-groups/{id:[0-9]+}/sub'", $routesContent);

        $this->assertTrue(file_exists(dirname(__DIR__) . '/../templates/voice_groups/index.twig'));
    }
}
