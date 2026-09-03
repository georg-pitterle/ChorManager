<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Controllers\DownloadController;
use App\Util\DownloadFileName;
use App\Models\Attachment;
use PHPUnit\Framework\TestCase;

class DownloadFeatureTest extends TestCase
{
    public function testDownloadStructureExists(): void
    {
        $this->assertTrue(class_exists(DownloadController::class));
        $this->assertTrue(class_exists(Attachment::class));
        $this->assertTrue(method_exists(DownloadController::class, 'index'));
        $this->assertTrue(method_exists(Attachment::class, 'song'));

        // Ausliefern kann nur noch AttachmentController. Bleiben diese Methoden
        // stehen, gibt es wieder zwei Wege an dieselbe Datei - mit zwei
        // Rechteprüfungen, die auseinanderlaufen können.
        $this->assertFalse(method_exists(DownloadController::class, 'downloadAttachment'));
        $this->assertFalse(method_exists(DownloadController::class, 'streamAttachment'));

        $routesContent = file_get_contents(dirname(__DIR__) . '/../src/Routes.php');
        $this->assertIsString($routesContent);
        $this->assertStringContainsString("'/downloads'", $routesContent);
        $this->assertStringNotContainsString('/downloads/attachments/', $routesContent);

        $controllerContent = file_get_contents(dirname(__DIR__) . '/../src/Controllers/DownloadController.php');
        $this->assertIsString($controllerContent);
        $this->assertStringContainsString("->join('project_users', 'project_users.project_id', '=', 'projects.id')", $controllerContent);
        $this->assertStringContainsString("->where('project_users.user_id', \$userId)", $controllerContent);

        $this->assertTrue(file_exists(dirname(__DIR__) . '/../templates/songs/downloads.twig'));
    }

    public function testNormalizeFileNameStripsUnsafeCharacters(): void
    {
        $name = DownloadFileName::sanitize(" bad\n\r\"\\/name.mp3 ");
        $this->assertSame('bad_____name.mp3', $name);

        $fallback = DownloadFileName::sanitize("\n\r\"\\/");
        $this->assertSame('_____', $fallback);
    }

    public function testDownloadTemplateRendersSeparateSongLinksSection(): void
    {
        $template = file_get_contents(dirname(__DIR__) . '/../templates/songs/downloads.twig');

        $this->assertIsString($template);
        $this->assertStringContainsString('song.linkResources', $template);
        $this->assertStringContainsString('target="_blank"', $template);
        $this->assertStringContainsString('rel="noopener noreferrer"', $template);
        $this->assertStringContainsString('Links', $template);
    }

    public function testDownloadControllerLoadsSongLinkResources(): void
    {
        $controllerContent = file_get_contents(dirname(__DIR__) . '/../src/Controllers/DownloadController.php');

        $this->assertIsString($controllerContent);
        $this->assertStringContainsString("'assignedSongs.linkResources' => function (" . '$' . "query)", $controllerContent);
        $this->assertStringContainsString("$" . "query->where('resource_type', 'link')", $controllerContent);
    }
}
