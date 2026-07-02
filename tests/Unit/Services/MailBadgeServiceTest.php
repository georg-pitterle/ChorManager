<?php

declare(strict_types=1);

namespace Tests\Unit\Services;

use App\Models\UserMailAccount;
use App\Services\MailBadgeService;
use App\Services\MailCredentialCryptoService;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;

class MailBadgeServiceTest extends TestCase
{
    private const ENV_KEY = 'MAIL_CREDENTIAL_KEY';

    private ?string $originalEnvValue = null;
    private bool $hadEnvValue = false;

    protected function setUp(): void
    {
        $this->hadEnvValue = array_key_exists(self::ENV_KEY, $_ENV);
        $this->originalEnvValue = $_ENV[self::ENV_KEY] ?? null;

        $generatedKey = base64_encode(random_bytes(SODIUM_CRYPTO_SECRETBOX_KEYBYTES));
        $_ENV[self::ENV_KEY] = $generatedKey;
        $_SERVER[self::ENV_KEY] = $generatedKey;
        putenv(self::ENV_KEY . '=' . $generatedKey);
    }

    protected function tearDown(): void
    {
        if ($this->hadEnvValue) {
            $_ENV[self::ENV_KEY] = $this->originalEnvValue;
            $_SERVER[self::ENV_KEY] = $this->originalEnvValue;
            putenv(self::ENV_KEY . '=' . $this->originalEnvValue);
        } else {
            unset($_ENV[self::ENV_KEY], $_SERVER[self::ENV_KEY]);
            putenv(self::ENV_KEY);
        }
    }

    public function testQuoteImapStringWrapsPlainValueInQuotes(): void
    {
        $this->assertSame('"plainvalue"', MailBadgeService::quoteImapString('plainvalue'));
    }

    public function testQuoteImapStringEscapesBackslashesAndQuotes(): void
    {
        $input = 'pass"with\\stuff';

        $quoted = MailBadgeService::quoteImapString($input);

        $this->assertSame('"pass\\"with\\\\stuff"', $quoted);

        // Manually reverse the escaping (strip surrounding quotes, unescape \\ and \")
        // to prove the original value is recoverable.
        $inner = substr($quoted, 1, -1);
        $recovered = str_replace(['\\\\', '\\"'], ['\\', '"'], $inner);
        $this->assertSame($input, $recovered);
    }

    public function testQuoteImapStringRejectsCrlfToPreventCommandInjection(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        MailBadgeService::quoteImapString("user\r\nA2 LOGOUT");
    }

    public function testParseStatusLineExtractsUnseenAndUidnextInGivenOrder(): void
    {
        $line = "* STATUS INBOX (UNSEEN 5 UIDNEXT 124)\r\n";

        $result = MailBadgeService::parseStatusLine($line);

        $this->assertSame(['unseen' => 5, 'uidnext' => 124], $result);
    }

    public function testParseStatusLineExtractsUnseenAndUidnextInReverseOrder(): void
    {
        $line = "* STATUS INBOX (UIDNEXT 124 UNSEEN 5)\r\n";

        $result = MailBadgeService::parseStatusLine($line);

        $this->assertSame(['unseen' => 5, 'uidnext' => 124], $result);
    }

    public function testParseStatusLineReturnsNullForNonMatchingLine(): void
    {
        $line = "* CAPABILITY IMAP4rev1 LOGINDISABLED\r\n";

        $result = MailBadgeService::parseStatusLine($line);

        $this->assertNull($result);
    }

    public function testRefreshReturnsFalseAndLeavesCacheUntouchedOnConnectionFailure(): void
    {
        $crypto = new MailCredentialCryptoService();
        $service = new MailBadgeService($crypto, new NullLogger(), 1);

        $account = new UserMailAccount();
        $account->user_id = 1;
        $account->imap_host = '127.0.0.1';
        // Port 1 is reserved (tcpmux) and reliably refused/unreachable on loopback in test envs.
        $account->imap_port = 1;
        $account->imap_encryption = 'none';
        $account->imap_username = 'someone@example.test';
        $account->imap_password_enc = $crypto->encrypt('irrelevant-password');
        $account->mail_last_unseen_count = 7;
        $account->mail_last_uid_seen = '99';
        $account->mail_last_checked_at = null;

        $result = $service->refresh($account);

        $this->assertFalse($result);
        $this->assertSame(7, $account->mail_last_unseen_count);
        $this->assertSame('99', $account->mail_last_uid_seen);
        $this->assertNull($account->mail_last_checked_at);
    }
}
