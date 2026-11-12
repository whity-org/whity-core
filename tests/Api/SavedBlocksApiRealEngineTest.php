<?php

declare(strict_types=1);

namespace Tests\Api;

use PDO;
use PHPUnit\Framework\TestCase;
use Tests\Support\SchemaFromMigrations;
use Whity\Api\DocumentSourcesApiHandler;
use Whity\Api\SavedBlocksApiHandler;
use Whity\Auth\RoleChecker;
use Whity\Core\Audit\AuditContext;
use Whity\Core\Audit\AuditLogger;
use Whity\Core\Document\DocumentAccessPolicy;
use Whity\Core\Document\DocumentSourceRepository;
use Whity\Core\Document\SavedBlock\SavedBlockRepository;
use Whity\Core\Ou\OuReachResolver;
use Whity\Core\RBAC\PermissionRegistry;
use Whity\Core\RBAC\ResourceRoleAssignmentRepository;
use Whity\Core\RBAC\ResourceTypeRegistry;
use Whity\Core\Request;
use Whity\Core\Tenant\TenantContext;
use Whity\Database\Database;
use Whity\Sdk\Http\Response;

/**
 * Real-engine tests for saved blocks (editor M7, {@see SavedBlocksApiHandler},
 * migrations 260 and 265). Runs on SQLite by default and on PostgreSQL when
 * PHPUNIT_PG_DSN is set (Tests\Support\SchemaFromMigrations).
 *
 * What matters: a person sees their own Only me blocks and every workspace
 * block, never someone else's Only me block or another workspace's; sharing
 * with the workspace and managing someone else's workspace block needs
 * documents:publish; a blocks change bumps the version and keeps the old one
 * (E.38); deleting archives, and a synced block still used answers 409 unless
 * a documents:publish holder forces it; the usage list shows only documents
 * the caller can see; recordUses replaces a document's rows.
 */
final class SavedBlocksApiRealEngineTest extends TestCase
{
    private const TENANT = 1;
    private const OTHER_TENANT = 2;

    private const OWNER = 10;   // admin: read, write, publish
    private const VIEWER = 11;  // read only
    private const WRITER = 12;  // read, write
    private const WRITER2 = 13; // read, write
    private const FOREIGN = 20; // read, write, publish in the other tenant

    private PDO $pdo;
    private SavedBlocksApiHandler $handler;
    private SavedBlockRepository $blocks;
    private DocumentSourceRepository $sources;

    protected function setUp(): void
    {
        RoleChecker::clearCache();
        AuditContext::reset();
        $this->pdo = $this->makeSchema();
        $roleChecker = new RoleChecker($this->wrap($this->pdo), new PermissionRegistry());
        $resourceRoles = new ResourceRoleAssignmentRepository($this->pdo, new ResourceTypeRegistry());
        $this->sources = new DocumentSourceRepository($this->pdo);
        $this->blocks = new SavedBlockRepository($this->pdo);
        $this->handler = new SavedBlocksApiHandler(
            $this->blocks,
            new DocumentSourcesApiHandler(
                $this->sources,
                new DocumentAccessPolicy(),
                $roleChecker,
                new OuReachResolver($this->pdo, $resourceRoles),
            ),
            $roleChecker,
            new AuditLogger($this->pdo),
        );
    }

    protected function tearDown(): void
    {
        TenantContext::reset();
        AuditContext::reset();
        RoleChecker::clearCache();
    }

    // ── create and list ──────────────────────────────────────────────────

    public function testCreatingStartsAtVersionOneWithItsVersionRow(): void
    {
        $res = $this->create(self::WRITER, ['name' => '  Bank details ', 'description' => 'Example Bakery account', 'mode' => 'synced', 'blocks' => $this->bank(), 'per_document_keys' => ['signer', 'signer']]);
        self::assertSame(201, $res->getStatusCode(), $res->getBody());
        $b = $this->data($res);
        self::assertSame('Bank details', $b['name']);
        self::assertSame('Example Bakery account', $b['description']);
        self::assertSame('synced', $b['mode']);
        self::assertSame('me', $b['sharing'], 'Only me unless asked');
        self::assertSame(['kind' => 'person', 'id' => self::WRITER, 'name' => 'Amro Saleh'], $b['owner']);
        self::assertSame($this->bank(), $b['blocks']);
        self::assertSame(['signer'], $b['perDocumentKeys']);
        self::assertSame(1, $b['version']);
        self::assertSame(0, $b['usedIn']);
        self::assertNull($b['archivedAt']);
        self::assertMatchesRegularExpression('/^\d{4}-\d\d-\d\dT\d\d:\d\d:\d\dZ$/', $b['createdAt']);

        $v1 = $this->data($this->call('version', self::WRITER, (int) $b['id'], ['version' => '1']));
        self::assertSame(['savedBlockId' => $b['id'], 'version' => 1, 'blocks' => $this->bank()], array_diff_key($v1, ['createdAt' => 1]));
        self::assertSame(1, (int) $this->q("SELECT COUNT(*) FROM audit_log WHERE action = 'documents.saved_block.created'")->fetchColumn());
    }

    public function testCreatingNeedsWriteAndWorkspaceSharingNeedsPublish(): void
    {
        self::assertSame(403, $this->create(self::VIEWER, $this->body())->getStatusCode(), 'read alone does not save blocks');

        $refused = $this->create(self::WRITER, $this->body(['sharing' => 'workspace']));
        self::assertSame(403, $refused->getStatusCode());
        self::assertSame('documents:publish', $this->details($refused)['required'] ?? null);

        self::assertSame(201, $this->create(self::OWNER, $this->body(['sharing' => 'workspace']))->getStatusCode());
    }

    public function testListShowsOwnOnlyMeAndWorkspaceBlocksButNotArchivedOnes(): void
    {
        $mine = $this->make(self::WRITER, ['name' => 'Mine']);
        $theirs = $this->make(self::WRITER2, ['name' => 'Theirs']);
        $shared = $this->make(self::OWNER, ['name' => 'Shared', 'sharing' => 'workspace']);
        $gone = $this->make(self::WRITER, ['name' => 'Gone']);
        self::assertSame(200, $this->call('delete', self::WRITER, $gone)->getStatusCode());
        $this->make(self::FOREIGN, ['name' => 'Foreign', 'sharing' => 'workspace'], self::OTHER_TENANT);

        $list = $this->data($this->call('list', self::WRITER));
        self::assertSame([$mine, $shared], array_column($list, 'id'));
        self::assertSame(['Mine', 'Shared'], array_column($list, 'name'));

        self::assertSame(404, $this->call('show', self::WRITER, $theirs)->getStatusCode(), "someone else's Only me block is hidden");
        self::assertSame(404, $this->call('usage', self::WRITER, $theirs)->getStatusCode());
        self::assertSame(404, $this->call('version', self::WRITER, $theirs, ['version' => '1'])->getStatusCode());
        self::assertSame(200, $this->call('show', self::VIEWER, $shared)->getStatusCode(), 'read sees workspace blocks');
        self::assertSame(200, $this->call('show', self::WRITER, $gone)->getStatusCode(), 'a deleted block still answers by id');
    }

    public function testAnotherTenantSeesNothing(): void
    {
        $id = $this->make(self::OWNER, ['sharing' => 'workspace']);

        self::assertSame([], $this->data($this->call('list', self::FOREIGN, 0, [], '', self::OTHER_TENANT)));
        self::assertSame(404, $this->call('show', self::FOREIGN, $id, [], '', self::OTHER_TENANT)->getStatusCode());
        self::assertSame(404, $this->patch(self::FOREIGN, $id, ['name' => 'Taken'], self::OTHER_TENANT)->getStatusCode());
        self::assertSame(404, $this->call('delete', self::FOREIGN, $id, [], '?force=1', self::OTHER_TENANT)->getStatusCode());
        self::assertSame(404, $this->call('version', self::FOREIGN, $id, ['version' => '1'], '', self::OTHER_TENANT)->getStatusCode());
        $row = $this->blocks->find($id, self::TENANT);
        self::assertNotNull($row);
        self::assertNull($row['archived_at'], "the other tenant's forced delete touched nothing");
    }

    // ── validation ───────────────────────────────────────────────────────

    public function testBodyValidation(): void
    {
        $bad = [
            'no name' => ['name' => '  '],
            'long name' => ['name' => str_repeat('x', 121)],
            'no mode' => ['mode' => 'linked'],
            'sharing' => ['sharing' => 'everyone'],
            'description' => ['description' => 5],
            'empty blocks' => ['blocks' => []],
            'blocks not a list' => ['blocks' => ['a' => ['id' => 'x', 'type' => 'paragraph']]],
            'block without id' => ['blocks' => [['type' => 'paragraph']]],
            'block without type' => ['blocks' => [['id' => 'x']]],
            'block not an object' => ['blocks' => ['paragraph']],
            'too many blocks' => ['blocks' => array_map(static fn (int $i): array => ['id' => "b{$i}", 'type' => 'divider'], range(1, 201))],
            'too large' => ['blocks' => [['id' => 'x', 'type' => 'code', 'text' => str_repeat('a', 262200)]]],
            'keys not a list' => ['per_document_keys' => 'signer'],
            'key not a string' => ['per_document_keys' => [3]],
        ];
        foreach ($bad as $case => $override) {
            $res = $this->create(self::WRITER, $this->body($override));
            self::assertSame(422, $res->getStatusCode(), $case . ': ' . $res->getBody());
        }
        self::assertSame(201, $this->create(self::WRITER, $this->body(['blocks' => array_map(static fn (int $i): array => ['id' => "b{$i}", 'type' => 'divider'], range(1, 200))]))->getStatusCode(), '200 blocks is the limit');
        self::assertSame(0, (int) $this->q('SELECT COUNT(*) FROM saved_block_versions WHERE version > 1')->fetchColumn());
    }

    // ── changes and versions ─────────────────────────────────────────────

    public function testChangingBlocksBumpsTheVersionAndKeepsTheOldOne(): void
    {
        $id = $this->make(self::WRITER);
        $next = [['id' => 's1', 'type' => 'paragraph', 'content' => [['type' => 'text', 'text' => 'Example Bakery, new IBAN']]]];

        $renamed = $this->data($this->patch(self::WRITER, $id, ['name' => 'Bank', 'description' => 'Main account']));
        self::assertSame('Bank', $renamed['name']);
        self::assertSame(1, $renamed['version'], 'a rename is not a new version');

        $same = $this->data($this->patch(self::WRITER, $id, ['blocks' => $this->bank()]));
        self::assertSame(1, $same['version'], 'the same blocks again change nothing');

        $changed = $this->data($this->patch(self::WRITER, $id, ['blocks' => $next, 'per_document_keys' => ['signer'], 'version' => 1]));
        self::assertSame(2, $changed['version']);
        self::assertSame($next, $changed['blocks']);
        self::assertSame(['signer'], $changed['perDocumentKeys']);

        self::assertSame($this->bank(), $this->data($this->call('version', self::WRITER, $id, ['version' => '1']))['blocks'], 'version 1 is kept (E.38)');
        self::assertSame($next, $this->data($this->call('version', self::WRITER, $id, ['version' => '2']))['blocks']);
        self::assertSame(404, $this->call('version', self::WRITER, $id, ['version' => '3'])->getStatusCode());

        $stale = $this->patch(self::WRITER, $id, ['blocks' => $this->bank(), 'version' => 1]);
        self::assertSame(409, $stale->getStatusCode(), 'a change against an old version is refused');
        self::assertSame('changed', $this->details($stale)['code'] ?? null);

        self::assertSame(422, $this->patch(self::WRITER, $id, ['mode' => 'copy'])->getStatusCode(), 'mode does not change');
        self::assertSame(422, $this->patch(self::WRITER, $id, ['blocks' => []])->getStatusCode());
        self::assertSame(2, (int) $this->q("SELECT COUNT(*) FROM audit_log WHERE action = 'documents.saved_block.updated'")->fetchColumn(), 'the no-op is not audited');
    }

    public function testWhoMayChangeABlock(): void
    {
        $mine = $this->make(self::WRITER);
        $shared = $this->make(self::OWNER, ['sharing' => 'workspace']);

        self::assertSame(403, $this->patch(self::VIEWER, $shared, ['name' => 'X'])->getStatusCode(), 'read alone changes nothing');
        self::assertSame(403, $this->patch(self::WRITER, $shared, ['name' => 'X'])->getStatusCode(), "someone else's workspace block needs publish");
        self::assertSame(403, $this->call('delete', self::WRITER, $shared)->getStatusCode());
        self::assertSame(404, $this->patch(self::WRITER2, $mine, ['name' => 'X'])->getStatusCode(), "someone else's Only me block is hidden");

        $share = $this->patch(self::WRITER, $mine, ['sharing' => 'workspace']);
        self::assertSame(403, $share->getStatusCode(), 'sharing with the workspace needs publish');
        self::assertSame('documents:publish', $this->details($share)['required'] ?? null);

        self::assertSame('Owner renamed', $this->data($this->patch(self::OWNER, $shared, ['name' => 'Owner renamed']))['name']);
        $promoted = $this->make(self::OWNER, ['sharing' => 'workspace']);
        self::assertSame(200, $this->patch(self::OWNER, $promoted, ['sharing' => 'me'])->getStatusCode());
        self::assertSame(404, $this->call('show', self::WRITER, $promoted)->getStatusCode(), 'back to Only me hides it again');
    }

    // ── delete, uses and usage ───────────────────────────────────────────

    public function testDeletingASyncedBlockStillUsedAnswers409UnlessForcedByPublish(): void
    {
        $id = $this->make(self::OWNER, ['sharing' => 'workspace']);
        $doc = $this->document(self::OWNER);
        $this->blocks->recordUses(self::TENANT, $doc, [[$id, 1]]);
        self::assertSame(1, $this->data($this->call('show', self::OWNER, $id))['usedIn']);

        $refused = $this->call('delete', self::OWNER, $id);
        self::assertSame(409, $refused->getStatusCode());
        self::assertSame(['code' => 'in_use', 'used_in' => 1], $this->details($refused));

        $own = $this->make(self::WRITER);
        $this->blocks->recordUses(self::TENANT, $this->document(self::WRITER), [[$own, 1]]);
        self::assertSame(409, $this->call('delete', self::WRITER, $own)->getStatusCode());
        self::assertSame(403, $this->call('delete', self::WRITER, $own, [], '?force=1')->getStatusCode(), 'force needs publish');

        $forced = $this->call('delete', self::OWNER, $id, [], '?force=1');
        self::assertSame(200, $forced->getStatusCode(), $forced->getBody());
        self::assertSame(['id' => $id, 'archived' => true, 'usedIn' => 1], $this->data($forced));
        self::assertNotNull($this->data($this->call('show', self::OWNER, $id))['archivedAt']);
        self::assertSame(409, $this->patch(self::OWNER, $id, ['name' => 'Back'])->getStatusCode(), 'a deleted block does not change');
        self::assertSame(404, $this->call('delete', self::OWNER, $id)->getStatusCode(), 'deleted already');
        self::assertSame($this->bank(), $this->data($this->call('version', self::OWNER, $id, ['version' => '1']))['blocks'], 'its versions stay for documents that use it');
        $audit = $this->q("SELECT metadata FROM audit_log WHERE action = 'documents.saved_block.archived'")->fetchColumn();
        self::assertIsString($audit);
        self::assertTrue(json_decode($audit, true)['forced'] ?? null);
    }

    public function testACopyBlockDeletesWhateverUsesIt(): void
    {
        $id = $this->make(self::WRITER, ['mode' => 'copy']);
        $this->blocks->recordUses(self::TENANT, $this->document(self::WRITER), [[$id, 1]]);

        self::assertSame(200, $this->call('delete', self::WRITER, $id)->getStatusCode());
    }

    public function testRecordUsesReplacesTheDocumentsRows(): void
    {
        $a = $this->make(self::OWNER, ['sharing' => 'workspace']);
        $b = $this->make(self::OWNER, ['sharing' => 'workspace']);
        $foreign = $this->make(self::FOREIGN, [], self::OTHER_TENANT);
        $doc = $this->document(self::OWNER);

        self::assertSame(2, $this->blocks->recordUses(self::TENANT, $doc, [[$a, 1], [$b, 1], [$a, 2], [$foreign, 1], [999999, 1]]));
        self::assertSame([$a => 2, $b => 1], $this->blocks->usesForSource(self::TENANT, $doc), 'the last version given wins; unknown and foreign ids record nothing');

        self::assertSame(1, $this->blocks->recordUses(self::TENANT, $doc, [[$b, 3]]));
        self::assertSame([$b => 3], $this->blocks->usesForSource(self::TENANT, $doc));
        self::assertSame(0, $this->blocks->countUses($a, self::TENANT));

        self::assertSame(0, $this->blocks->recordUses(self::TENANT, $doc, []));
        self::assertSame([], $this->blocks->usesForSource(self::TENANT, $doc));
    }

    public function testUsageListsOnlyDocumentsTheCallerCanSee(): void
    {
        $id = $this->make(self::OWNER, ['sharing' => 'workspace']);
        $open = $this->document(self::OWNER, 'tenant', 'Example Bakery terms');
        $personal = $this->document(self::OWNER, 'personal', 'Private letter');
        $this->blocks->recordUses(self::TENANT, $open, [[$id, 1]]);
        $this->blocks->recordUses(self::TENANT, $personal, [[$id, 1]]);

        $res = $this->call('usage', self::WRITER, $id);
        self::assertSame(200, $res->getStatusCode(), $res->getBody());
        self::assertSame([['id' => $open, 'title' => 'Example Bakery terms', 'kind' => 'template', 'version' => 1]], $this->data($res));
        self::assertSame(['total' => 2, 'hidden' => 1], $this->decoded($res)['meta']);
        self::assertSame(2, $this->data($this->call('show', self::WRITER, $id))['usedIn']);
        self::assertCount(2, $this->data($this->call('usage', self::OWNER, $id)));
    }

    // ── helpers ──────────────────────────────────────────────────────────

    /** @return list<array<string, mixed>> */
    private function bank(): array
    {
        return [
            ['id' => 's1', 'type' => 'paragraph', 'content' => [['type' => 'text', 'text' => 'Example Bakery, IBAN JO00 0000']]],
            ['id' => 's2', 'type' => 'paragraph', 'content' => [['type' => 'text', 'text' => 'Signed by '], ['type' => 'text', 'text' => 'name', 'marks' => [['type' => 'perDocument', 'key' => 'signer']]]]],
        ];
    }

    /**
     * @param array<string, mixed> $override
     * @return array<string, mixed>
     */
    private function body(array $override = []): array
    {
        return $override + ['name' => 'Bank details', 'mode' => 'synced', 'blocks' => $this->bank()];
    }

    /** @param array<string, mixed> $override */
    private function make(int $userId, array $override = [], int $tenant = self::TENANT): int
    {
        $res = $this->create($userId, $this->body($override), $tenant);
        self::assertSame(201, $res->getStatusCode(), $res->getBody());

        return (int) $this->data($res)['id'];
    }

    private function document(int $owner, string $scope = 'tenant', string $title = 'Terms'): int
    {
        return $this->sources->create(self::TENANT, [
            'kind' => 'template',
            'title' => $title,
            'lang' => 'en',
            'default_view' => 'page',
            'blocks' => [['id' => 'b1', 'type' => 'paragraph', 'content' => [['type' => 'text', 'text' => 'Hello']]]],
            'scope' => $scope,
            'created_by' => $owner,
        ]);
    }

    private function request(string $method, string $path, int $userId, string $body = '', int $tenant = self::TENANT): Request
    {
        TenantContext::reset();
        TenantContext::setTenantId($tenant);
        $req = new Request($method, $path, [], $body);
        $req->user = (object) ['profile_id' => $userId, 'active_tenant_id' => $tenant];

        return $req;
    }

    /** @param array<string, mixed> $body */
    private function create(int $userId, array $body, int $tenant = self::TENANT): Response
    {
        return $this->handler->create($this->request('POST', '/api/saved-blocks', $userId, (string) json_encode($body), $tenant), []);
    }

    /** @param array<string, mixed> $body */
    private function patch(int $userId, int $id, array $body, int $tenant = self::TENANT): Response
    {
        return $this->handler->update($this->request('PATCH', "/api/saved-blocks/{$id}", $userId, (string) json_encode($body), $tenant), ['id' => (string) $id]);
    }

    /** @param array<string, string> $params */
    private function call(string $method, int $userId, int $id = 0, array $params = [], string $query = '', int $tenant = self::TENANT): Response
    {
        $verb = $method === 'delete' ? 'DELETE' : 'GET';

        return $this->handler->{$method}($this->request($verb, "/api/saved-blocks/{$id}" . $query, $userId, '', $tenant), ['id' => (string) $id] + $params);
    }

    /** @return array<mixed> */
    private function decoded(Response $res): array
    {
        $decoded = json_decode($res->getBody(), true);
        self::assertIsArray($decoded, $res->getBody());

        return $decoded;
    }

    /** @return array<mixed> */
    private function data(Response $res): array
    {
        $decoded = $this->decoded($res);
        self::assertArrayHasKey('data', $decoded, $res->getBody());

        return $decoded['data'];
    }

    /** @return array<string, mixed> */
    private function details(Response $res): array
    {
        $decoded = json_decode($res->getBody(), true);

        return is_array($decoded['details'] ?? null) ? $decoded['details'] : [];
    }

    private function q(string $sql): \PDOStatement
    {
        $stmt = $this->pdo->query($sql);
        self::assertNotFalse($stmt, $sql);

        return $stmt;
    }

    private function makeSchema(): PDO
    {
        $pdo = SchemaFromMigrations::make(true);
        $pdo->exec("INSERT INTO tenants (id, name, slug) VALUES (1, 'Example Bakery', 'example-bakery'), (2, 'Example Florist', 'example-florist')");
        $pdo->exec("INSERT OR IGNORE INTO roles (id, name, description, tenant_id, created_at) VALUES (1, 'admin', '', NULL, datetime('now'))");
        $pdo->exec("INSERT INTO roles (id, name, description, tenant_id, created_at) VALUES
            (101, 'viewer', '', 1, datetime('now')),
            (102, 'writer', '', 1, datetime('now')),
            (201, 'manager', '', 2, datetime('now'))");
        foreach ([
            101 => ['documents:read'],
            102 => ['documents:read', 'documents:write'],
            201 => ['documents:read', 'documents:write', 'documents:publish'],
        ] as $role => $permissions) {
            foreach ($permissions as $permission) {
                $this->grant($pdo, $role, $permission);
            }
        }
        $pdo->exec("
            INSERT INTO profiles (id, display_name, password_hash, two_factor_enabled, two_factor_backup_codes_version, token_epoch, created_at, updated_at) VALUES
                (10, 'Awss Haddad',      'x', false, 0, 0, CURRENT_TIMESTAMP, CURRENT_TIMESTAMP),
                (11, 'Ahmad Khalil',     'x', false, 0, 0, CURRENT_TIMESTAMP, CURRENT_TIMESTAMP),
                (12, 'Amro Saleh',       'x', false, 0, 0, CURRENT_TIMESTAMP, CURRENT_TIMESTAMP),
                (13, 'Ibrahim Mansour',  'x', false, 0, 0, CURRENT_TIMESTAMP, CURRENT_TIMESTAMP),
                (20, 'Emad Nasser',      'x', false, 0, 0, CURRENT_TIMESTAMP, CURRENT_TIMESTAMP)
        ");
        $pdo->exec("
            INSERT INTO memberships (id, profile_id, tenant_id, role_id, status, created_at) VALUES
                (1000, 10, 1, 1,   'active', datetime('now')),
                (1001, 11, 1, 101, 'active', datetime('now')),
                (1002, 12, 1, 102, 'active', datetime('now')),
                (1003, 13, 1, 102, 'active', datetime('now')),
                (2000, 20, 2, 201, 'active', datetime('now'))
        ");

        return $pdo;
    }

    private function grant(PDO $pdo, int $roleId, string $permission): void
    {
        $pdo->prepare('INSERT OR IGNORE INTO permissions (name, description, created_at) VALUES (?, ?, NOW())')
            ->execute([$permission, '']);
        $sel = $pdo->prepare('SELECT id FROM permissions WHERE name = ?');
        $sel->execute([$permission]);
        $pid = (int) $sel->fetchColumn();
        $pdo->prepare('INSERT OR IGNORE INTO role_permissions (role_id, permission_id, created_at) VALUES (?, ?, NOW())')
            ->execute([$roleId, $pid]);
    }

    private function wrap(PDO $pdo): Database
    {
        $db = Database::withFactory(static fn (): PDO => $pdo);
        $db->setMaxLifetimeSeconds(86400);
        $db->setPingIntervalSeconds(86400);
        $db->forceConnect();

        return $db;
    }
}
