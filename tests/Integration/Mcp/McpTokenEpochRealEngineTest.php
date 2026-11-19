<?php

declare(strict_types=1);

namespace Tests\Integration\Mcp;

use PDO;
use PHPUnit\Framework\TestCase;
use Tests\Support\SchemaFromMigrations;
use Whity\Auth\JwtParser;
use Whity\Auth\TokenValidator;
use Whity\Mcp\Auth\McpTokenService;

/**
 * #2262: a 90-day MCP token is bound to the profile's token epoch, like the
 * device credential. Issuing one is self-service off any live session, so a
 * stolen 15-minute access token could otherwise be swapped for a credential
 * that survived a password change, a reset and "Sign out other sessions"
 * (all of which move the epoch).
 *
 * Real engine (SQLite by default, PostgreSQL with PHPUNIT_PG_DSN).
 */
final class McpTokenEpochRealEngineTest extends TestCase
{
    private const SECRET  = 'mcp-token-epoch-test-secret-padded-32b';
    private const PROFILE = 93001;
    private const TENANT  = 1;

    private PDO $pdo;
    private JwtParser $jwt;
    private TokenValidator $validator;
    private McpTokenService $tokens;

    protected function setUp(): void
    {
        $this->pdo = SchemaFromMigrations::make();
        $this->jwt = new JwtParser(self::SECRET);
        $this->validator = new TokenValidator($this->jwt, $this->pdo);
        $this->tokens = new McpTokenService($this->pdo, $this->jwt);

        $now = $this->pdo->getAttribute(PDO::ATTR_DRIVER_NAME) === 'pgsql' ? 'NOW()' : "datetime('now')";
        $this->pdo->exec("INSERT INTO tenants (id, name, created_at) VALUES (1, 'tenant-a', {$now}) ON CONFLICT (id) DO NOTHING");
        $this->pdo->prepare(
            "INSERT INTO profiles (id, display_name, password_hash, two_factor_enabled,
                two_factor_backup_codes_version, token_epoch, status, created_at, updated_at)
             VALUES (?, 'holder', 'x', false, 0, 3, 'active', {$now}, {$now})"
        )->execute([self::PROFILE]);
        $roleStmt = $this->pdo->query("SELECT id FROM roles WHERE name = 'user'");
        self::assertNotFalse($roleStmt);
        $this->pdo->prepare(
            "INSERT INTO memberships (profile_id, tenant_id, role_id, status, created_at)
             VALUES (?, ?, ?, 'active', {$now})"
        )->execute([self::PROFILE, self::TENANT, (int) $roleStmt->fetchColumn()]);
    }

    public function testATokenCarriesTheEpochItWasIssuedAt(): void
    {
        $claims = $this->jwt->parse($this->tokens->issue(self::PROFILE, self::TENANT, 'laptop', ['tools:read']));

        self::assertIsArray($claims);
        self::assertSame(3, $claims['token_epoch'] ?? null);
    }

    public function testMovingTheEpochEndsTheToken(): void
    {
        $token = $this->tokens->issue(self::PROFILE, self::TENANT, 'laptop', ['tools:read']);
        self::assertNotNull($this->validator->validateMcpToken($token));

        // What a password change, a reset or "Sign out other sessions" does.
        $this->pdo->prepare('UPDATE profiles SET token_epoch = token_epoch + 1 WHERE id = ?')->execute([self::PROFILE]);

        self::assertNull($this->validator->validateMcpToken($token));
        self::assertNull($this->validator->validateBearerForMcp($token));
    }

    public function testATokenFromBeforeTheClaimCountsAsEpochZero(): void
    {
        // Registered like a real one, but minted without the claim.
        $token = $this->jwt->create([
            'profile_id' => self::PROFILE, 'active_tenant_id' => self::TENANT,
            'aud' => 'mcp', 'principal_kind' => 'user', 'scope' => ['tools:read'],
        ], 3600, 'mcp');
        $claims = $this->jwt->parse($token);
        self::assertIsArray($claims);
        $this->pdo->prepare(
            "INSERT INTO mcp_tokens (jti, profile_id, tenant_id, name, principal_kind, scope, expires_at)
             VALUES (?, ?, ?, 'old', 'user', '[\"tools:read\"]', ?)"
        )->execute([$claims['jti'], self::PROFILE, self::TENANT, date('Y-m-d H:i:s', time() + 3600)]);

        // This profile's epoch has moved (3), so the old token is stale.
        self::assertNull($this->validator->validateMcpToken($token));

        // A profile whose epoch never moved keeps its old tokens working.
        $this->pdo->prepare('UPDATE profiles SET token_epoch = 0 WHERE id = ?')->execute([self::PROFILE]);
        self::assertNotNull($this->validator->validateMcpToken($token));
    }
}
