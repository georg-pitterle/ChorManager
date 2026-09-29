<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\FileFolder;
use App\Models\FileFolderShare;
use App\Models\Project;
use App\Models\Role;
use App\Models\User;
use App\Models\VoiceGroup;
use App\Services\Files\FileActor;
use App\Services\Files\LocalFileStorage;
use App\Util\PasswordHasher;
use Tests\Unit\Bootstrap;

/**
 * Gemeinsame Fixtures der Dateiverwaltungs-Tests. Jede Zeile entsteht in einer
 * Transaktion, die tearDown zurückrollt; die Dateien landen in einem eigenen
 * Temp-Verzeichnis, das ebenso wieder verschwindet.
 */
trait FileFixtures
{
    protected string $storageDir = '';

    protected function setUpFileFixtures(): void
    {
        Bootstrap::setupTestDatabase();
        Bootstrap::getCapsule()?->connection()->beginTransaction();
        $this->storageDir = sys_get_temp_dir() . '/files-test-' . bin2hex(random_bytes(6));
        $_SESSION = [];
    }

    protected function tearDownFileFixtures(): void
    {
        $connection = Bootstrap::getCapsule()?->connection();
        if ($connection !== null && $connection->transactionLevel() > 0) {
            $connection->rollBack();
        }
        self::removeDirectory($this->storageDir);
        $_SESSION = [];
    }

    protected static function removeDirectory(string $dir): void
    {
        if ($dir === '' || !is_dir($dir)) {
            return;
        }
        $items = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($dir, \FilesystemIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::CHILD_FIRST
        );
        foreach ($items as $item) {
            $item->isDir() ? rmdir($item->getPathname()) : unlink($item->getPathname());
        }
        rmdir($dir);
    }

    protected function storage(): LocalFileStorage
    {
        return new LocalFileStorage($this->storageDir);
    }

    protected function createMember(string $label = 'Mitglied'): User
    {
        return User::create([
            'first_name' => $label,
            'last_name' => 'Test ' . bin2hex(random_bytes(3)),
            'email' => 'files-' . bin2hex(random_bytes(6)) . '@example.test',
            'password' => PasswordHasher::hash('irrelevant'),
            'is_active' => 1,
        ]);
    }

    protected function createRoleFor(User $user, string $name = 'Rolle'): Role
    {
        $role = Role::create([
            'name' => $name . ' ' . bin2hex(random_bytes(4)),
            'hierarchy_level' => 10,
        ]);
        $user->roles()->attach($role->id);

        return $role;
    }

    protected function createVoiceGroupFor(User $user, string $name = 'Sopran'): VoiceGroup
    {
        $group = VoiceGroup::create(['name' => $name . ' ' . bin2hex(random_bytes(4))]);
        $user->voiceGroups()->attach($group->id);

        return $group;
    }

    protected function createProjectFor(User $user, string $name = 'Projekt'): Project
    {
        $project = Project::create(['name' => $name . ' ' . bin2hex(random_bytes(4))]);
        $user->projects()->attach($project->id);

        return $project;
    }

    protected function createFolder(string $name, ?FileFolder $parent = null, ?int $quota = null): FileFolder
    {
        return FileFolder::create([
            'parent_id' => $parent?->id,
            'name' => $name,
            'quota_bytes' => $quota,
        ]);
    }

    protected function share(FileFolder $folder, string $type, int $referenceId, int $level): FileFolderShare
    {
        return FileFolderShare::create([
            'folder_id' => $folder->id,
            'target_type' => $type,
            'reference_id' => $referenceId,
            'level' => $level,
        ]);
    }

    protected function actor(User $user, bool $isAdmin = false): FileActor
    {
        return new FileActor((int) $user->id, $isAdmin);
    }
}
