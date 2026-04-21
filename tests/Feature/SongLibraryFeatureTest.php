<?php

declare(strict_types=1);

namespace Tests\Feature;

use PHPUnit\Framework\TestCase;

class SongLibraryFeatureTest extends TestCase
{
    public function testSongLibraryStructureExists(): void
    {
        $this->assertTrue(class_exists(\App\Controllers\SongLibraryController::class));
        $this->assertTrue(method_exists(\App\Controllers\SongLibraryController::class, 'index'));
        $this->assertTrue(method_exists(\App\Controllers\SongLibraryController::class, 'show'));
        $this->assertTrue(method_exists(\App\Controllers\SongLibraryController::class, 'createSong'));
        $this->assertTrue(method_exists(\App\Controllers\SongLibraryController::class, 'updateSong'));
        $this->assertTrue(method_exists(\App\Controllers\SongLibraryController::class, 'deleteSong'));
        $this->assertTrue(method_exists(\App\Controllers\SongLibraryController::class, 'uploadAttachments'));
        $this->assertTrue(method_exists(\App\Controllers\SongLibraryController::class, 'deleteAttachment'));
        $this->assertTrue(method_exists(\App\Controllers\SongLibraryController::class, 'syncCategories'));

        $routesContent = file_get_contents(dirname(__DIR__) . '/../src/Routes.php');
        $this->assertIsString($routesContent);
        $this->assertStringContainsString("'/song-library'", $routesContent);
        $this->assertStringContainsString("'/{id:[0-9]+}', [SongLibraryController::class, 'show']", $routesContent);
        $this->assertStringContainsString("'/songs/{id:[0-9]+}/attachments'", $routesContent);
        $this->assertStringContainsString(
            'new RoleMiddleware(false, 0, false, false, false, false, false, true)',
            $routesContent
        );
        $this->assertTrue(file_exists(dirname(__DIR__) . '/../templates/songs/manage.twig'));
        $this->assertTrue(file_exists(dirname(__DIR__) . '/../templates/songs/detail.twig'));
    }

    public function testSongDeleteAlsoRemovesAttachments(): void
    {
        $controllerContent = file_get_contents(dirname(__DIR__) . '/../src/Controllers/SongLibraryController.php');

        $this->assertIsString($controllerContent);
        $this->assertStringContainsString("Attachment::where('entity_type', 'song')", $controllerContent);
        $this->assertStringContainsString("->where('entity_id', " . '$' . "songId)", $controllerContent);
        $this->assertStringContainsString("->delete();", $controllerContent);
    }

    public function testSongUploadValidatesDeclaredMimeBeforePersisting(): void
    {
        $controllerContent = file_get_contents(dirname(__DIR__) . '/../src/Controllers/SongLibraryController.php');

        $this->assertIsString($controllerContent);
        $this->assertStringContainsString(
            "trim((string) " . '$' . "file->getClientMediaType()) ?: 'application/octet-stream'",
            $controllerContent
        );
        $this->assertStringContainsString(
            '$contents = $file->getStream()->getContents();',
            $controllerContent
        );
        $this->assertStringContainsString(
            '$size = strlen($contents);',
            $controllerContent
        );
        $this->assertStringContainsString(
            "UploadValidator::validateFileSize(" . '$' . "size, " . '$' . "mimeType)",
            $controllerContent
        );
        $this->assertStringContainsString(
            "'mime_type' => UploadValidator::normalizeMimeType(" . '$' . "mimeType)",
            $controllerContent
        );
        $this->assertStringContainsString("'file_size' => " . '$' . "size", $controllerContent);
    }

    public function testDevSeedServiceSeedsSongsAndSongAttachments(): void
    {
        $seedContent = file_get_contents(dirname(__DIR__) . '/../src/Services/DevSeedService.php');

        $this->assertIsString($seedContent);
        $this->assertStringContainsString("'songs'", $seedContent);
        $this->assertStringContainsString('$categories = $this->seedCategories();', $seedContent);
        $this->assertStringContainsString('$songs = $this->seedSongs($users[\'active\']);', $seedContent);
        $this->assertStringContainsString('$this->seedProjectSongAssignments($songs, $projects);', $seedContent);
        $this->assertStringContainsString('$this->seedSongAttachments($songs, 48);', $seedContent);
    }
}
