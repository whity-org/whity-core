<?php

declare(strict_types=1);

namespace Tests\Cli;

use PHPUnit\Framework\TestCase;
use Tests\Support\SchemaFromMigrations;
use Whity\Cli\Commands\AuthCommand;

/**
 * The break-glass `whity-cli auth password-sign-in on|off` is on the audit
 * trail like the Settings screen's switch (READINESS CO-7), as coming from the
 * command line and naming no person.
 */
final class AuthCommandAuditTest extends TestCase
{
    public function testTheBreakGlassSwitchIsAuditedFromTheCommandLine(): void
    {
        $pdo = SchemaFromMigrations::make();
        $pdo->exec("INSERT INTO tenants (id, name, slug) VALUES (0, 'system', 'system') ON CONFLICT DO NOTHING");

        $audited = new \ReflectionMethod(AuthCommand::class, 'audited');
        $audited->invoke(null, $pdo, true);

        $stmt = $pdo->query("SELECT tenant_id, actor_user_id, actor_kind, metadata FROM audit_log WHERE action = 'settings.changed'");
        self::assertNotFalse($stmt);
        $row = $stmt->fetch(\PDO::FETCH_ASSOC);
        self::assertIsArray($row);
        self::assertSame(0, (int) $row['tenant_id']);
        self::assertNull($row['actor_user_id'], 'a shell names no person');
        $metadata = json_decode((string) $row['metadata'], true);
        self::assertTrue($metadata['break_glass']);
        self::assertSame('auth.password_sign_in', $metadata['changes'][0]['key']);
        self::assertSame('true', $metadata['changes'][0]['to']);
    }
}
