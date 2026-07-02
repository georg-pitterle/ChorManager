<?php

declare(strict_types=1);

namespace Tests\Unit\Models;

use App\Models\User;
use App\Models\UserMailAccount;
use Illuminate\Database\QueryException;
use PHPUnit\Framework\TestCase;
use Tests\Unit\Bootstrap;

final class UserMailAccountTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        Bootstrap::setupTestDatabase();
    }

    private function createTestUser(): User
    {
        return User::create([
            'first_name' => 'Mail',
            'last_name' => 'Tester',
            'email' => 'mail.tester.' . bin2hex(random_bytes(4)) . '@example.test',
            'password' => password_hash('test123', PASSWORD_DEFAULT),
            'is_active' => 1,
        ]);
    }

    public function testUserMailAccountBelongsToUserAndUserHasMailAccount(): void
    {
        $user = $this->createTestUser();

        $account = UserMailAccount::create([
            'user_id' => $user->id,
            'imap_host' => 'imap.example.test',
            'imap_port' => 993,
            'imap_encryption' => 'ssl',
            'imap_username' => 'mail.tester',
            'imap_password_enc' => 'plain-placeholder',
            'imap_enabled' => 1,
            'mail_badge_enabled' => 1,
        ]);

        $fetchedAccount = UserMailAccount::find($account->id);
        $this->assertSame($user->id, $fetchedAccount->user->id);

        $fetchedUser = User::find($user->id);
        $this->assertSame($account->id, $fetchedUser->mailAccount->id);

        $account->delete();
        $user->delete();
    }

    public function testBooleanCastsReturnRealBooleans(): void
    {
        $user = $this->createTestUser();

        $account = UserMailAccount::create([
            'user_id' => $user->id,
            'imap_host' => 'imap.example.test',
            'imap_port' => 993,
            'imap_encryption' => 'ssl',
            'imap_username' => 'mail.tester',
            'imap_password_enc' => 'plain-placeholder',
            'imap_enabled' => 1,
            'mail_badge_enabled' => 0,
        ]);

        $fresh = UserMailAccount::find($account->id);

        $this->assertIsBool($fresh->imap_enabled);
        $this->assertIsBool($fresh->mail_badge_enabled);
        $this->assertTrue($fresh->imap_enabled);
        $this->assertFalse($fresh->mail_badge_enabled);

        $account->delete();
        $user->delete();
    }

    public function testUserIdMustBeUniqueAtDatabaseLevel(): void
    {
        $user = $this->createTestUser();

        $account = UserMailAccount::create([
            'user_id' => $user->id,
            'imap_host' => 'imap.example.test',
            'imap_port' => 993,
            'imap_encryption' => 'ssl',
            'imap_username' => 'mail.tester',
            'imap_password_enc' => 'plain-placeholder',
            'imap_enabled' => 1,
            'mail_badge_enabled' => 1,
        ]);

        $this->expectException(QueryException::class);

        try {
            UserMailAccount::create([
                'user_id' => $user->id,
                'imap_host' => 'imap2.example.test',
                'imap_port' => 993,
                'imap_encryption' => 'tls',
                'imap_username' => 'mail.tester2',
                'imap_password_enc' => 'plain-placeholder-2',
                'imap_enabled' => 1,
                'mail_badge_enabled' => 1,
            ]);
        } finally {
            $account->delete();
            $user->delete();
        }
    }
}
