<?php

declare(strict_types=1);

namespace Tests\Feature;

use Illuminate\Database\Capsule\Manager as Capsule;
use PHPUnit\Framework\TestCase;
use Tests\Unit\Bootstrap;

/**
 * Das Schema des OIDC-Providers.
 *
 * Geprüft wird hier nicht die Fachlogik, sondern dass die Tabellen und die
 * beiden neuen Spalten überhaupt so dastehen, wie die Dienste sie erwarten:
 * Geheimnisse ausschließlich als Hash, eine Zuordnung pro Nextcloud-Kennung,
 * und keine Karteileichen nach einem gelöschten Mitglied.
 */
final class OidcSchemaFeatureTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        Bootstrap::setupTestDatabase();
    }

    /**
     * @return iterable<string, array{0:string, 1:list<string>}>
     */
    public static function tableColumnProvider(): iterable
    {
        yield 'oidc_clients' => ['oidc_clients', [
            'id', 'client_id', 'client_secret_hash', 'name', 'redirect_uris',
            'post_logout_redirect_uris', 'is_trusted', 'is_active', 'created_at', 'updated_at',
        ]];

        yield 'oidc_auth_codes' => ['oidc_auth_codes', [
            'id', 'code_hash', 'client_id', 'user_id', 'redirect_uri', 'scope', 'nonce',
            'code_challenge', 'code_challenge_method', 'expires_at', 'used_at', 'created_at',
        ]];

        yield 'oidc_access_tokens' => ['oidc_access_tokens', [
            'id', 'token_hash', 'client_id', 'user_id', 'scope', 'expires_at', 'revoked_at', 'created_at',
        ]];

        yield 'oidc_signing_keys' => ['oidc_signing_keys', [
            'id', 'kid', 'public_key', 'private_key_encrypted', 'is_active', 'created_at',
        ]];
    }

    /**
     * @param list<string> $expectedColumns
     */
    #[\PHPUnit\Framework\Attributes\DataProvider('tableColumnProvider')]
    public function testOidcTablesCarryTheExpectedColumns(string $table, array $expectedColumns): void
    {
        $columns = $this->columnsOf($table);

        foreach ($expectedColumns as $column) {
            $this->assertContains($column, $columns, $table . ' braucht die Spalte ' . $column . '.');
        }
    }

    public function testSecretsAndTokensAreStoredUniqueAndHashedOnly(): void
    {
        $this->assertNotContains('client_secret', $this->columnsOf('oidc_clients'));
        $this->assertNotContains('code', $this->columnsOf('oidc_auth_codes'));
        $this->assertNotContains('token', $this->columnsOf('oidc_access_tokens'));

        $this->assertTrue($this->hasUniqueIndex('oidc_clients', 'client_id'));
        $this->assertTrue($this->hasUniqueIndex('oidc_auth_codes', 'code_hash'));
        $this->assertTrue($this->hasUniqueIndex('oidc_access_tokens', 'token_hash'));
        $this->assertTrue($this->hasUniqueIndex('oidc_signing_keys', 'kid'));
    }

    public function testCodesAndTokensVanishWithTheirUser(): void
    {
        foreach (['oidc_auth_codes', 'oidc_access_tokens'] as $table) {
            $foreignKey = $this->foreignKeyFor($table, 'user_id');

            $this->assertNotNull($foreignKey, $table . '.user_id braucht einen Fremdschlüssel auf users.');
            $this->assertSame('users', $foreignKey['REFERENCED_TABLE_NAME']);
            $this->assertSame('CASCADE', $this->deleteRuleFor((string) $foreignKey['CONSTRAINT_NAME']));
        }
    }

    public function testUsersCarryAnExternalUidAndRolesAnExternalGroup(): void
    {
        $this->assertContains('external_uid', $this->columnsOf('users'));
        $this->assertContains('external_group', $this->columnsOf('roles'));

        $this->assertTrue(
            $this->hasUniqueIndex('users', 'external_uid'),
            'Eine Nextcloud-Kennung darf nicht zwei Mitgliedern gehören.'
        );
    }

    /**
     * @return list<string>
     */
    private function columnsOf(string $table): array
    {
        $rows = Capsule::connection()->select(
            'SELECT COLUMN_NAME FROM information_schema.COLUMNS '
                . 'WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ?',
            [$table]
        );

        return array_map(static fn($row): string => (string) $row->COLUMN_NAME, $rows);
    }

    private function hasUniqueIndex(string $table, string $column): bool
    {
        $rows = Capsule::connection()->select(
            'SELECT INDEX_NAME FROM information_schema.STATISTICS '
                . 'WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND COLUMN_NAME = ? '
                . 'AND NON_UNIQUE = 0 AND SEQ_IN_INDEX = 1',
            [$table, $column]
        );

        return $rows !== [];
    }

    /**
     * @return array<string, mixed>|null
     */
    private function foreignKeyFor(string $table, string $column): ?array
    {
        $rows = Capsule::connection()->select(
            'SELECT CONSTRAINT_NAME, REFERENCED_TABLE_NAME, REFERENCED_COLUMN_NAME '
                . 'FROM information_schema.KEY_COLUMN_USAGE '
                . 'WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND COLUMN_NAME = ? '
                . 'AND REFERENCED_TABLE_NAME IS NOT NULL',
            [$table, $column]
        );

        return $rows === [] ? null : (array) $rows[0];
    }

    private function deleteRuleFor(string $constraintName): string
    {
        $rows = Capsule::connection()->select(
            'SELECT DELETE_RULE FROM information_schema.REFERENTIAL_CONSTRAINTS '
                . 'WHERE CONSTRAINT_SCHEMA = DATABASE() AND CONSTRAINT_NAME = ?',
            [$constraintName]
        );

        return $rows === [] ? '' : (string) $rows[0]->DELETE_RULE;
    }
}
