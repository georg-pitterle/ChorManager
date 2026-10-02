<?php

declare(strict_types=1);

namespace App\Services\Files;

use App\Models\FileFolderShare;
use App\Models\FilePublicLink;
use App\Models\FileShare;
use App\Models\StoredFile;
use App\Services\Audience\AudienceFilterService;
use App\Util\PasswordHasher;
use Carbon\Carbon;
use Illuminate\Database\Capsule\Manager as DB;
use Psr\Log\LoggerInterface;

/**
 * Freigaben auf einzelne Dateien und öffentliche Links.
 *
 * Beides verwaltet, wer den Ordner der Datei verwaltet - dieselbe Stufe wie
 * für Ordnerfreigaben. Eine Dateifreigabe gibt höchstens Bearbeiten; öffentliche
 * Links geben nur Lesen.
 */
final class FileShareService
{
    private const TOKEN_PATTERN = '/^[A-Za-z0-9_-]{32}$/';

    public function __construct(
        private readonly FileAccessService $access,
        private readonly FileFolderService $folders,
        private readonly LoggerInterface $logger,
        private readonly AudienceFilterService $filters = new AudienceFilterService()
    ) {
    }

    public function canManage(FileActor $actor, StoredFile $file): bool
    {
        return $this->access->fileLevelFor($actor, $file) >= FileFolderShare::LEVEL_MANAGE;
    }

    /**
     * @param array<int, mixed> $rawShares Einträge mit type, reference_id, level
     */
    public function setShares(FileActor $actor, StoredFile $file, array $rawShares): void
    {
        $this->requireManage($actor, $file);
        $shares = $this->folders->normalizeShares($rawShares, array_keys(FileShare::LEVELS));

        DB::connection()->transaction(function () use ($actor, $file, $shares): void {
            $old = FileShare::query()->where('file_id', $file->id)->pluck('audience_filter_id')
                ->map(fn ($id): int => (int) $id)->all();
            FileShare::query()->where('file_id', $file->id)->delete();
            $this->filters->delete($old);
            foreach ($shares as $share) {
                $filter = $this->filters->create($share['conditions']);
                FileShare::create([
                    'file_id' => (int) $file->id,
                    'audience_filter_id' => (int) $filter->id,
                    'level' => $share['level'],
                    'created_by' => $actor->userId,
                ]);
            }
        });

        $this->logger->info('File shares changed.', [
            'event' => 'files.file_share_changed',
            'file_id' => (int) $file->id,
            'share_count' => count($shares),
            'user_id' => $actor->userId,
        ]);
    }

    /**
     * @return array{0: FilePublicLink, 1: string} Link und Klartext-Token (nur jetzt verfügbar)
     */
    public function createLink(
        FileActor $actor,
        StoredFile $file,
        ?string $label,
        ?Carbon $expiresAt,
        ?string $password
    ): array {
        $this->requireManage($actor, $file);

        if ($expiresAt !== null && $expiresAt->lessThanOrEqualTo(Carbon::now())) {
            throw new FileManagementException('Das Ablaufdatum muss in der Zukunft liegen.', 422);
        }
        $label = $label !== null ? mb_substr(trim($label), 0, 120) : null;
        $password = $password !== null && $password !== '' ? $password : null;

        $token = rtrim(strtr(base64_encode(random_bytes(24)), '+/', '-_'), '=');
        $link = FilePublicLink::create([
            'file_id' => (int) $file->id,
            'token_hash' => hash('sha256', $token),
            'label' => $label === '' ? null : $label,
            'password_hash' => $password === null ? null : PasswordHasher::hash($password),
            'expires_at' => $expiresAt,
            'created_by' => $actor->userId,
        ]);

        $this->logger->info('Public file link created.', [
            'event' => 'files.public_link_created',
            'file_id' => (int) $file->id,
            'link_id' => (int) $link->id,
            'expires' => $expiresAt !== null,
            'password' => $password !== null,
            'user_id' => $actor->userId,
        ]);

        return [$link, $token];
    }

    public function revokeLink(FileActor $actor, int $linkId): FilePublicLink
    {
        $link = FilePublicLink::find($linkId);
        $file = $link !== null ? StoredFile::find($link->file_id) : null;
        if ($link === null || $file === null) {
            throw FileManagementException::notFound();
        }
        $this->requireManage($actor, $file);

        if ($link->revoked_at === null) {
            $link->revoked_at = Carbon::now();
            $link->save();
        }

        $this->logger->info('Public file link revoked.', [
            'event' => 'files.public_link_revoked',
            'link_id' => (int) $link->id,
            'user_id' => $actor->userId,
        ]);

        return $link;
    }

    /**
     * Link zu einem Token, sofern er gilt: nicht widerrufen, nicht abgelaufen,
     * Datei und alle Ordner darüber nicht im Papierkorb.
     */
    public function resolvePublic(string $token): ?FilePublicLink
    {
        if (preg_match(self::TOKEN_PATTERN, $token) !== 1) {
            return null;
        }

        $link = FilePublicLink::query()->where('token_hash', hash('sha256', $token))->first();
        if ($link === null || !$link->isUsable()) {
            return null;
        }

        $file = StoredFile::find($link->file_id);
        if ($file === null || $this->access->isInTrash((int) $file->folder_id)) {
            return null;
        }

        return $link;
    }

    public function checkPassword(FilePublicLink $link, string $password): bool
    {
        return $link->hasPassword() && password_verify($password, (string) $link->password_hash);
    }

    public function recordDownload(FilePublicLink $link): void
    {
        FilePublicLink::query()->whereKey($link->id)->update([
            'download_count' => DB::raw('download_count + 1'),
            'last_used_at' => Carbon::now(),
        ]);

        $this->logger->info('Public file link used.', [
            'event' => 'files.public_link_downloaded',
            'link_id' => (int) $link->id,
            'file_id' => (int) $link->file_id,
        ]);
    }

    private function requireManage(FileActor $actor, StoredFile $file): void
    {
        $level = $this->access->fileLevelFor($actor, $file);
        if ($level === FileFolderShare::LEVEL_NONE) {
            throw FileManagementException::notFound();
        }
        if ($level < FileFolderShare::LEVEL_MANAGE) {
            throw FileManagementException::forbidden();
        }
    }
}
