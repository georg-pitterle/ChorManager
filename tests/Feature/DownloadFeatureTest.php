<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Controllers\DownloadController;
use App\Models\Attachment;
use PHPUnit\Framework\TestCase;

class DownloadFeatureTest extends TestCase
{
    public function testDownloadStructureExists(): void
    {
        $this->assertTrue(class_exists(DownloadController::class));
        $this->assertTrue(class_exists(Attachment::class));
        $this->assertTrue(method_exists(DownloadController::class, 'index'));
        $this->assertTrue(method_exists(DownloadController::class, 'downloadAttachment'));
        $this->assertTrue(method_exists(DownloadController::class, 'streamAttachment'));
        $this->assertTrue(method_exists(Attachment::class, 'song'));

        $routesContent = file_get_contents(dirname(__DIR__) . '/../src/Routes.php');
        $this->assertIsString($routesContent);
        $this->assertStringContainsString("'/downloads'", $routesContent);
        $this->assertStringContainsString("'/downloads/attachments/{attachment_id:[0-9]+}/download'", $routesContent);
        $this->assertStringContainsString("'/downloads/attachments/{attachment_id:[0-9]+}/stream'", $routesContent);

        $controllerContent = file_get_contents(dirname(__DIR__) . '/../src/Controllers/DownloadController.php');
        $this->assertIsString($controllerContent);
        $this->assertStringContainsString("->join('project_users', 'project_users.project_id', '=', 'projects.id')", $controllerContent);
        $this->assertStringContainsString("->where('project_users.user_id', \$userId)", $controllerContent);
        $this->assertStringContainsString("->join('project_users', 'project_users.project_id', '=', 'songs.project_id')", $controllerContent);

        $this->assertTrue(file_exists(dirname(__DIR__) . '/../templates/songs/downloads.twig'));
    }

    public function testNormalizeFileNameStripsUnsafeCharacters(): void
    {
        $name = DownloadController::normalizeFileName(" bad\n\r\"\\/name.mp3 ");
        $this->assertSame('bad_____name.mp3', $name);

        $fallback = DownloadController::normalizeFileName("\n\r\"\\/");
        $this->assertSame('_____', $fallback);
    }

    public function testParseRangeHeaderAcceptsValidRange(): void
    {
        $range = DownloadController::parseRangeHeader('bytes=10-20', 100);
        $this->assertSame([10, 20], $range);

        $openEndedRange = DownloadController::parseRangeHeader('bytes=25-', 100);
        $this->assertSame([25, 99], $openEndedRange);

        $suffixRange = DownloadController::parseRangeHeader('bytes=-10', 100);
        $this->assertSame([90, 99], $suffixRange);

        $largeSuffixRange = DownloadController::parseRangeHeader('bytes=-200', 100);
        $this->assertSame([0, 99], $largeSuffixRange);
    }

    public function testParseRangeHeaderRejectsInvalidRanges(): void
    {
        $this->assertNull(DownloadController::parseRangeHeader('bytes=20-10', 100));
        $this->assertNull(DownloadController::parseRangeHeader('bytes=100-101', 100));
        $this->assertNull(DownloadController::parseRangeHeader('bytes=-0', 100));
        $this->assertNull(DownloadController::parseRangeHeader('invalid', 100));
        $this->assertNull(DownloadController::parseRangeHeader('bytes=0-0', 0));
    }
}
