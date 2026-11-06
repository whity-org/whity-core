<?php

declare(strict_types=1);

namespace Tests\Integration;

use PDO;
use PHPUnit\Framework\TestCase;
use Tests\Support\SchemaFromMigrations;
use Whity\Api\UsersApiHandler;
use Whity\Auth\RoleChecker;
use Whity\Core\Hooks\HookManager;
use Whity\Core\Request;
use Whity\Core\Tenant\TenantContext;
use Whity\Sdk\Hooks\HookVetoException;

/**
 * WHIT-911: `user.creating` is read-only and no user hook carries a password
 * (SDK 1.70).
 *
 * Drives the real {@see UsersApiHandler} with a real {@see HookManager} on the
 * real engine (SQLite here, PostgreSQL in the Integration shards when
 * PHPUNIT_PG_DSN is set) and reads the persisted rows back. It proves that:
 *
 *  - the `user.creating` payload is exactly `email` and `role_id`;
 *  - a listener's returned role or email is ignored, so the membership gets the
 *    role the caller asked for and was checked for;
 *  - a listener can still refuse the create, and then nothing is written;
 *  - no hook the create or a password change dispatches carries the password.
 */
final class UserCreatingHookRealEngineTest extends TestCase
{
    private const TENANT = 1;
    private const ADMIN_ROLE = 1;
    private const USER_ROLE = 2;

    private const PASSWORD = 'Plaintext-for-WHIT-911-test-9f2c';
    private const NEW_PASSWORD = 'Another-plaintext-for-WHIT-911-7d1a';

    private PDO $pdo;

    /** @var \ArrayObject<int, array{event: string, payload: array<string, mixed>}> */
    private \ArrayObject $recorder;

    private HookManager $hooks;

    protected function setUp(): void
    {
        RoleChecker::clearCache();
        TenantContext::reset();
        $this->pdo = $this->makeSchema();

        $recorder = new \ArrayObject();
        $this->recorder = $recorder;
        // Records every sync and async dispatch, then runs the real listeners.
        $this->hooks = new class ($recorder) extends HookManager {
            /** @param \ArrayObject<int, array{event: string, payload: array<string, mixed>}> $recorder */
            public function __construct(private readonly \ArrayObject $recorder)
            {
                parent::__construct();
            }

            /**
             * @param array<string, mixed> $data
             * @return array<string, mixed>
             */
            public function dispatch(string $eventName, array $data): array
            {
                $this->recorder->append(['event' => $eventName, 'payload' => $data]);

                return parent::dispatch($eventName, $data);
            }

            /** @param array<string, mixed> $payload */
            public function dispatchAsync(string $eventName, array $payload): void
            {
                $this->recorder->append(['event' => $eventName, 'payload' => $payload]);
            }
        };
    }

    protected function tearDown(): void
    {
        RoleChecker::clearCache();
        TenantContext::reset();
    }

    public function testUserCreatingPayloadIsEmailAndRoleOnly(): void
    {
        $seen = null;
        $this->hooks->listen('user.creating', function (array $data) use (&$seen): array {
            $seen = $data;

            return $data;
        });

        $response = $this->create(['email' => 'plain@a.example', 'password' => self::PASSWORD, 'role' => 'user']);

        $this->assertSame(201, $response->getStatusCode(), $response->getBody());
        $this->assertIsArray($seen, 'user.creating must be dispatched');
        $this->assertSame(['email', 'role_id'], array_keys($seen));
        $this->assertSame('plain@a.example', $seen['email']);
        $this->assertSame(self::USER_ROLE, $seen['role_id']);
    }

    public function testAListenerCannotChangeTheRoleOrEmail(): void
    {
        $this->hooks->listen('user.creating', static function (array $data): array {
            $data['role_id'] = self::ADMIN_ROLE;
            $data['email'] = 'someone-else@a.example';
            $data['password'] = 'chosen-by-the-listener';

            return $data;
        });

        $response = $this->create(['email' => 'ordinary@a.example', 'password' => self::PASSWORD, 'role' => 'user']);

        $this->assertSame(201, $response->getStatusCode(), $response->getBody());

        $row = $this->query(
            "SELECT m.role_id, p.password_hash FROM memberships m
             JOIN profile_emails pe ON pe.profile_id = m.profile_id
             JOIN profiles p ON p.id = m.profile_id
             WHERE pe.email = 'ordinary@a.example' AND m.tenant_id = " . self::TENANT
        )->fetch(PDO::FETCH_ASSOC);

        $this->assertIsArray($row, 'The user must be created under the email the caller sent');
        $this->assertSame(self::USER_ROLE, (int) $row['role_id'], 'The role a listener returns must be ignored');
        $this->assertTrue(password_verify(self::PASSWORD, (string) $row['password_hash']), 'The caller\'s password must be the one stored');
        $this->assertSame(
            0,
            (int) $this->query("SELECT COUNT(*) FROM profile_emails WHERE email = 'someone-else@a.example'")->fetchColumn(),
            'The email a listener returns must be ignored'
        );

        $data = json_decode($response->getBody(), true)['data'];
        $this->assertSame('user', $data['role']);
    }

    public function testAListenerCanRefuseTheCreateAndNothingIsWritten(): void
    {
        $this->hooks->listen('user.creating', static function (array $data): array {
            throw new HookVetoException('user.creating', 'Not on the allow list');
        });

        $response = $this->create(['email' => 'refused@a.example', 'password' => self::PASSWORD]);

        $this->assertSame(409, $response->getStatusCode(), $response->getBody());
        $this->assertStringContainsString('Not on the allow list', $response->getBody());
        $this->assertSame(
            0,
            (int) $this->query("SELECT COUNT(*) FROM profile_emails WHERE email = 'refused@a.example'")->fetchColumn()
        );
        $this->assertNotContains('user.created', array_column($this->recorder->getArrayCopy(), 'event'));
    }

    public function testNoHookDispatchedByCreateOrPasswordChangeCarriesThePassword(): void
    {
        $created = $this->create(['email' => 'sweep@a.example', 'password' => self::PASSWORD, 'role' => 'user']);
        $this->assertSame(201, $created->getStatusCode(), $created->getBody());
        $profileId = (int) json_decode($created->getBody(), true)['data']['id'];

        TenantContext::setTenantId(self::TENANT);
        $updated = $this->handler()->update(
            $this->req('PATCH', '/api/users/' . $profileId, ['password' => self::NEW_PASSWORD]),
            ['id' => (string) $profileId]
        );
        $this->assertSame(200, $updated->getStatusCode(), $updated->getBody());

        $events = array_column($this->recorder->getArrayCopy(), 'event');
        $this->assertContains('user.creating', $events);
        $this->assertContains('user.created', $events);
        $this->assertContains('user.created.async', $events);
        $this->assertContains('user.updated', $events);

        foreach ($this->recorder as $hook) {
            $flat = (string) json_encode($hook['payload']);
            $this->assertStringNotContainsString(self::PASSWORD, $flat, $hook['event'] . ' carries the password');
            $this->assertStringNotContainsString(self::NEW_PASSWORD, $flat, $hook['event'] . ' carries the new password');
            $this->assertStringNotContainsString('$2y$', $flat, $hook['event'] . ' carries a password hash');
            foreach (array_keys($hook['payload']) as $key) {
                $this->assertDoesNotMatchRegularExpression(
                    '/password|secret|token|hash/i',
                    (string) $key,
                    $hook['event'] . ' has a secret-shaped key'
                );
            }
        }
    }

    // ── helpers ──────────────────────────────────────────────────────────────

    /** @param array<string, mixed> $body */
    private function create(array $body): \Whity\Core\Response
    {
        TenantContext::setTenantId(self::TENANT);
        $response = $this->handler()->create($this->req('POST', '/api/users', $body));
        TenantContext::reset();

        return $response;
    }

    private function query(string $sql): \PDOStatement
    {
        $statement = $this->pdo->query($sql);
        $this->assertInstanceOf(\PDOStatement::class, $statement);

        return $statement;
    }

    private function handler(): UsersApiHandler
    {
        return new UsersApiHandler($this->pdo, $this->hooks);
    }

    /** @param array<string, mixed>|null $body */
    private function req(string $method, string $path, ?array $body): Request
    {
        $request = new Request($method, $path, [], $body !== null ? (string) json_encode($body) : '');
        $request->user = (object) ['profile_id' => 99, 'active_tenant_id' => self::TENANT];

        return $request;
    }

    private function makeSchema(): PDO
    {
        $pdo = SchemaFromMigrations::make(true);

        $pdo->exec("INSERT OR IGNORE INTO tenants (id, name) VALUES (0, 'system')");
        $pdo->exec("INSERT INTO tenants (id, name) VALUES (1, 'tenant-a')");
        $pdo->exec("
            INSERT OR IGNORE INTO roles (id, name, description, tenant_id, created_at) VALUES
                (1, 'admin', '', NULL, datetime('now')),
                (2, 'user',  '', NULL, datetime('now'))
        ");

        return $pdo;
    }
}
