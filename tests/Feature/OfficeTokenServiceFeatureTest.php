<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\OfficeAccessToken;
use App\Models\StoredFile;
use App\Models\User;
use App\Services\Files\FileActor;
use App\Services\Office\OfficeTokenService;
use Carbon\Carbon;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;

/**
 * Zugangstokens für Collabora: nur als Hash gespeichert, an genau eine Datei
 * gebunden, zehn Stunden gültig.
 */
class OfficeTokenServiceFeatureTest extends TestCase
{
    use FileFixtures;

    protected function setUp(): void
    {
        $this->setUpFileFixtures();
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        $this->tearDownFileFixtures();
    }

    private function tokens(): OfficeTokenService
    {
        return new OfficeTokenService(new NullLogger());
    }

    /** @return array{0: User, 1: StoredFile} */
    private function userAndFile(): array
    {
        $user = $this->createMember();
        $folder = $this->createFolder('Vorstand ' . bin2hex(random_bytes(3)));
        $file = StoredFile::create([
            'folder_id' => $folder->id,
            'name' => 'Protokoll ' . bin2hex(random_bytes(3)) . '.odt',
            'size' => 1,
            'mime_type' => 'application/vnd.oasis.opendocument.text',
            'created_by' => $user->id,
            'updated_by' => $user->id,
        ]);

        return [$user, $file];
    }

    public function testIssuedTokenIsStoredOnlyAsHash(): void
    {
        [$user, $file] = $this->userAndFile();

        $issued = $this->tokens()->issue((int) $user->id, (int) $file->id);

        $this->assertMatchesRegularExpression('/^[A-Za-z0-9_-]{43}$/', $issued->plain);
        $row = OfficeAccessToken::query()->where('file_id', $file->id)->sole();
        $this->assertSame(hash('sha256', $issued->plain), $row->token_hash);
        $this->assertSame((int) $user->id, $row->user_id);
    }

    public function testTokenLivesTenHoursAndTtlIsAbsoluteMilliseconds(): void
    {
        Carbon::setTestNow('2026-10-04 12:00:00');
        [$user, $file] = $this->userAndFile();

        $issued = $this->tokens()->issue((int) $user->id, (int) $file->id);

        $this->assertSame(Carbon::parse('2026-10-04 22:00:00')->getTimestamp() * 1000, $issued->ttlMilliseconds());
    }

    public function testResolveChecksTokenFileAndExpiry(): void
    {
        [$user, $file] = $this->userAndFile();
        [, $other] = $this->userAndFile();
        $issued = $this->tokens()->issue((int) $user->id, (int) $file->id);

        $this->assertSame((int) $user->id, $this->tokens()->resolve($issued->plain, (int) $file->id)?->user_id);
        $this->assertNull($this->tokens()->resolve($issued->plain, (int) $other->id), 'Nur für die eigene Datei.');
        $this->assertNull($this->tokens()->resolve('falsch', (int) $file->id));
        $this->assertNull($this->tokens()->resolve('', (int) $file->id));
        $this->assertNull($this->tokens()->resolve(str_repeat('a', 500), (int) $file->id));

        Carbon::setTestNow(Carbon::now()->addSeconds(OfficeTokenService::TTL_SECONDS + 1));
        $this->assertNull($this->tokens()->resolve($issued->plain, (int) $file->id), 'Abgelaufen.');
    }

    public function testIssuingRemovesExpiredTokens(): void
    {
        [$user, $file] = $this->userAndFile();
        Carbon::setTestNow('2026-10-04 08:00:00');
        $this->tokens()->issue((int) $user->id, (int) $file->id);

        Carbon::setTestNow('2026-10-05 08:00:00');
        $this->tokens()->issue((int) $user->id, (int) $file->id);

        $this->assertSame(1, OfficeAccessToken::query()->where('user_id', $user->id)->count());
    }

    public function testActorForUserReflectsActiveFlagAndFileAdminRole(): void
    {
        $member = $this->createMember();
        $this->assertFalse(FileActor::forUser($member)?->isFileAdmin);

        $role = $this->createRoleFor($member);
        $role->can_manage_files = 1;
        $role->save();
        $this->assertTrue(FileActor::forUser($member->fresh())?->isFileAdmin);

        $member->is_active = 0;
        $member->save();
        $this->assertNull(FileActor::forUser($member->fresh()), 'Deaktiviert heißt: kein Zugriff.');
    }
}
