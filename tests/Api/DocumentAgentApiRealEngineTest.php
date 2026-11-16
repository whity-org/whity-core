<?php

declare(strict_types=1);

namespace Tests\Api;

use PDO;
use PHPUnit\Framework\TestCase;
use Tests\Support\SchemaFromMigrations;
use Whity\Api\DocumentAgentApiHandler;
use Whity\Api\DocumentCollabApiHandler;
use Whity\Api\DocumentSourcesApiHandler;
use Whity\Auth\RoleChecker;
use Whity\Core\Agent\AgentAssignmentRepository;
use Whity\Core\Audit\Actor;
use Whity\Core\Audit\AuditContext;
use Whity\Core\Audit\AuditLogger;
use Whity\Core\Document\Agent\DocumentAgentRequestRepository;
use Whity\Core\Document\Collab\DocumentCollab;
use Whity\Core\Document\Comment\DocumentCommentRepository;
use Whity\Core\Document\DocumentAccessPolicy;
use Whity\Core\Document\DocumentSourceRepository;
use Whity\Core\Document\DocumentSourceVersionRepository;
use Whity\Core\Document\Suggestion\DocumentSuggestionRepository;
use Whity\Core\Ou\OuReachResolver;
use Whity\Core\RBAC\PermissionRegistry;
use Whity\Core\RBAC\ResourceRoleAssignmentRepository;
use Whity\Core\RBAC\ResourceTypeRegistry;
use Whity\Core\Request;
use Whity\Core\Tenant\TenantContext;
use Whity\Sdk\Http\Response;

/**
 * Agents in the editor (editor M6, {@see DocumentAgentApiHandler}). Runs on
 * SQLite by default and on PostgreSQL when PHPUNIT_PG_DSN is set.
 *
 * What matters: a person asks and their own agent picks it up; the agent
 * reads the draft only for a request it is working on; everything it writes
 * is a suggestion or a comment recording the agent and the person, never a
 * draft change; Stop reaches the agent on its next update; requests belong to
 * the person who asked, and to their tenant.
 */
final class DocumentAgentApiRealEngineTest extends TestCase
{
    private const TENANT = 1;
    private const OTHER_TENANT = 2;
    private const WRITER = 12;   // read, write, suggest, comment
    private const VIEWER = 11;   // read only
    private const OTHER = 13;    // read, suggest, comment
    private const FOREIGN = 20;  // the other tenant
    private const AGENT = 'agent-jti-1';

    private PDO $pdo;
    private DocumentAgentApiHandler $handler;
    private DocumentSourceRepository $repo;
    private DocumentAgentRequestRepository $requests;

    protected function setUp(): void
    {
        RoleChecker::clearCache();
        AuditContext::reset();
        $this->pdo = $this->makeSchema();
        $roleChecker = new RoleChecker($this->wrap($this->pdo), new PermissionRegistry());
        $this->repo = new DocumentSourceRepository($this->pdo);
        $collab = new DocumentCollab($this->pdo, $this->repo, new DocumentSuggestionRepository($this->pdo), new DocumentCommentRepository($this->pdo));
        $sources = new DocumentSourcesApiHandler(
            $this->repo,
            new DocumentAccessPolicy(),
            $roleChecker,
            new OuReachResolver($this->pdo, new ResourceRoleAssignmentRepository($this->pdo, new ResourceTypeRegistry())),
            collab: $collab,
        );
        $audit = new AuditLogger($this->pdo);
        $this->requests = new DocumentAgentRequestRepository($this->pdo);
        $this->handler = new DocumentAgentApiHandler(
            $sources,
            new DocumentCollabApiHandler($sources, $collab, $roleChecker, $audit),
            $collab,
            $this->requests,
            new DocumentSourceVersionRepository($this->pdo),
            new AgentAssignmentRepository($this->pdo),
            $roleChecker,
            $audit,
        );
    }

    protected function tearDown(): void
    {
        TenantContext::reset();
        AuditContext::reset();
        RoleChecker::clearCache();
    }

    public function testAPersonAsksTheirAgentPicksItUpAndOnlySuggests(): void
    {
        $id = $this->document();

        $asked = $this->call('ask', self::WRITER, ['id' => (string) $id], ['request' => 'Shorten the payment terms', 'anchor_block_id' => 'b1', 'quote' => '30 days']);
        self::assertSame(201, $asked->getStatusCode(), $asked->getBody());
        $rid = (int) $this->data($asked)['id'];
        self::assertSame('queued', $this->data($asked)['status']);
        self::assertNull($this->data($asked)['agent'], 'any of my agents');

        $this->asAgent(self::WRITER);
        $next = $this->data($this->call('next', self::WRITER, []));
        self::assertSame($rid, $next['id']);
        self::assertSame('running', $next['status']);
        self::assertSame($id, $next['document_id']);
        self::assertSame(['id' => self::AGENT, 'name' => 'Billing assistant'], $next['agent']);
        self::assertNull($this->data($this->call('next', self::WRITER, [])), 'nothing else waits');

        $read = $this->call('read', self::WRITER, ['id' => (string) $id], null, '?request_id=' . $rid);
        self::assertSame(200, $read->getStatusCode(), $read->getBody());
        self::assertSame('draft', $this->data($read)['reading']);
        self::assertSame('b1', $this->data($read)['blocks'][0]['id']);

        $made = $this->call('suggestRewrite', self::WRITER, ['id' => (string) $id], [
            'block_id' => 'b1', 'delete_text' => '30', 'insert' => '14', 'summary' => 'Replace 30 days with 14 days', 'request_id' => $rid,
        ]);
        self::assertSame(201, $made->getStatusCode(), $made->getBody());
        $s = $this->data($made);
        self::assertSame('open', $s['status']);
        self::assertSame('Shorten the payment terms', $s['request']);
        self::assertSame('documents:suggest', $s['permission']);
        self::assertSame(['kind' => 'agent', 'id' => self::AGENT, 'name' => 'Billing assistant', 'for' => ['id' => self::WRITER, 'name' => 'writer']], $s['by']);

        $blocks = $this->call('suggestBlocks', self::WRITER, ['id' => (string) $id], [
            'after_block_id' => 'b1', 'summary' => 'Add a late fee line',
            'blocks' => [['id' => 'n1', 'type' => 'paragraph', 'content' => [['type' => 'text', 'text' => 'Late payments carry a fee.']]]],
        ]);
        self::assertSame(201, $blocks->getStatusCode(), $blocks->getBody());

        $row = $this->repo->findById($id, self::TENANT);
        self::assertSame(1, (int) ($row['draft_version'] ?? 0), 'the draft is never changed by an agent');
        self::assertSame('Pay within 30 days.', $row['blocks'][0]['content'][0]['text'] ?? null);

        $done = $this->data($this->call('progress', self::WRITER, ['rid' => (string) $rid], ['status' => 'done', 'detail' => 'Two suggestions']));
        self::assertFalse($done['stop']);
        self::assertSame('done', $done['request']['status']);
    }

    public function testStopReachesTheAgentOnItsNextUpdate(): void
    {
        $id = $this->document();
        $rid = (int) $this->data($this->call('ask', self::WRITER, ['id' => (string) $id], ['request' => 'Check the totals']))['id'];
        $this->asAgent(self::WRITER);
        $this->call('next', self::WRITER, []);

        $running = $this->data($this->call('progress', self::WRITER, ['rid' => (string) $rid], ['status' => 'running', 'title' => 'Reading 4 tables', 'done' => 1, 'total' => 4]));
        self::assertFalse($running['stop']);
        self::assertSame(['done' => 1, 'total' => 4], $running['request']['progress']);
        self::assertSame('Reading 4 tables', $running['request']['title']);

        AuditContext::reset();
        $stopped = $this->data($this->call('stop', self::WRITER, ['rid' => (string) $rid]));
        self::assertTrue($stopped['stopping']);

        $this->asAgent(self::WRITER);
        self::assertTrue($this->data($this->call('progress', self::WRITER, ['rid' => (string) $rid], ['status' => 'running']))['stop']);
        $final = $this->data($this->call('progress', self::WRITER, ['rid' => (string) $rid], ['status' => 'stopped']));
        self::assertSame('stopped', $final['request']['status']);
        self::assertTrue($this->data($this->call('progress', self::WRITER, ['rid' => (string) $rid], ['status' => 'running']))['stop'], 'a finished request takes no more reports');
    }

    public function testAQueuedRequestStopsAtOnce(): void
    {
        $id = $this->document();
        $rid = (int) $this->data($this->call('ask', self::WRITER, ['id' => (string) $id], ['request' => 'Tidy up']))['id'];
        self::assertSame('stopped', $this->data($this->call('stop', self::WRITER, ['rid' => (string) $rid]))['status']);
        $this->asAgent(self::WRITER);
        self::assertNull($this->data($this->call('next', self::WRITER, [])));
    }

    public function testOnlyAgentsPickUpAndOnlyPeopleAsk(): void
    {
        $id = $this->document();
        self::assertSame(403, $this->call('next', self::WRITER, [])->getStatusCode(), 'a person does not pick up requests');
        $this->asAgent(self::WRITER);
        self::assertSame(403, $this->call('ask', self::WRITER, ['id' => (string) $id], ['request' => 'x'])->getStatusCode(), 'an agent never asks an agent');
        AuditContext::reset();
        self::assertSame(403, $this->call('ask', self::VIEWER, ['id' => (string) $id], ['request' => 'x'])->getStatusCode(), 'asking needs documents:suggest');
        self::assertSame(422, $this->call('ask', self::WRITER, ['id' => (string) $id], ['request' => '  '])->getStatusCode());
        self::assertSame(422, $this->call('ask', self::WRITER, ['id' => (string) $id], ['request' => 'x', 'agent_id' => 99])->getStatusCode(), 'an agent you are not using');
    }

    public function testRequestsBelongToThePersonWhoAsked(): void
    {
        $id = $this->document();
        $rid = (int) $this->data($this->call('ask', self::WRITER, ['id' => (string) $id], ['request' => 'Mine']))['id'];

        $this->asAgent(self::OTHER, 'agent-jti-2');
        self::assertNull($this->data($this->call('next', self::OTHER, [])), "another person's agent never sees it");
        self::assertSame(404, $this->call('progress', self::OTHER, ['rid' => (string) $rid], ['status' => 'running'])->getStatusCode());
        AuditContext::reset();
        self::assertSame(404, $this->call('stop', self::OTHER, ['rid' => (string) $rid])->getStatusCode());
        self::assertSame([], $this->data($this->call('listRequests', self::OTHER, ['id' => (string) $id])));
        self::assertCount(1, $this->data($this->call('listRequests', self::WRITER, ['id' => (string) $id])));

        self::assertSame(404, $this->call('stop', self::FOREIGN, ['rid' => (string) $rid], [], '', self::OTHER_TENANT)->getStatusCode(), 'nor another workspace');
    }

    public function testAnAgentReadsThePublishedVersionOutsideARequest(): void
    {
        $id = $this->document();
        $this->asAgent(self::WRITER);
        $res = $this->call('read', self::WRITER, ['id' => (string) $id]);
        self::assertSame(409, $res->getStatusCode(), 'not published, and not asked from the editor');
        self::assertSame(422, $this->call('read', self::WRITER, ['id' => (string) $id], null, '?request_id=999')->getStatusCode());

        AuditContext::reset();
        self::assertSame('draft', $this->data($this->call('read', self::WRITER, ['id' => (string) $id]))['reading'], 'a person reads the draft');
    }

    public function testTheReplyToolRecordsTheAgentAndThePerson(): void
    {
        $id = $this->document();
        $tid = (new DocumentCommentRepository($this->pdo))->createThread(self::TENANT, $id, 'b1', 'Pay', self::WRITER);
        $this->asAgent(self::WRITER);

        $res = $this->call('reply', self::WRITER, ['id' => (string) $id, 'tid' => (string) $tid], ['text' => 'Done, see the suggestion', 'suggestion_ids' => [7]]);
        self::assertSame(201, $res->getStatusCode(), $res->getBody());
        $m = $this->data($res);
        self::assertSame('agent', $m['by']['kind']);
        self::assertSame(self::WRITER, $m['by']['for']['id']);
        self::assertSame("Done, see the suggestion\nSuggested: #7", $m['body'][0]['text']);
        self::assertSame(422, $this->call('reply', self::WRITER, ['id' => (string) $id, 'tid' => (string) $tid], ['text' => ''])->getStatusCode());
    }

    // ── helpers ──────────────────────────────────────────────────────────

    private function asAgent(int $person, string $jti = self::AGENT): void
    {
        AuditContext::reset();
        AuditContext::setActor(Actor::agent($jti, $person));
    }

    private function document(): int
    {
        return $this->repo->create(self::TENANT, [
            'kind' => 'template',
            'title' => 'Terms',
            'lang' => 'en',
            'default_view' => 'page',
            'blocks' => [
                ['id' => 'b1', 'type' => 'paragraph', 'content' => [['type' => 'text', 'text' => 'Pay within 30 days.']]],
            ],
            'scope' => 'tenant',
            'created_by' => self::WRITER,
        ]);
    }

    /**
     * @param array<string, string>     $params
     * @param array<string, mixed>|null $body
     */
    private function call(string $method, int $userId, array $params, ?array $body = [], string $query = '', int $tenant = self::TENANT): Response
    {
        TenantContext::reset();
        TenantContext::setTenantId($tenant);
        $req = new Request($body === null ? 'GET' : 'POST', '/api/x' . $query, [], $body === null ? '' : (string) json_encode($body));
        $req->user = (object) ['profile_id' => $userId, 'active_tenant_id' => $tenant];

        return $this->handler->{$method}($req, $params);
    }

    /** @return mixed */
    private function data(Response $res): mixed
    {
        $decoded = json_decode($res->getBody(), true);
        self::assertIsArray($decoded, $res->getBody());
        self::assertArrayHasKey('data', $decoded, $res->getBody());

        return $decoded['data'];
    }

    private function makeSchema(): PDO
    {
        $pdo = SchemaFromMigrations::make(true);
        $pdo->exec("INSERT INTO tenants (id, name, slug) VALUES (1, 'a', 'a'), (2, 'b', 'b')");
        $pdo->exec("INSERT INTO roles (id, name, description, tenant_id, created_at) VALUES
            (101, 'viewer', '', 1, datetime('now')),
            (102, 'writer', '', 1, datetime('now')),
            (103, 'suggester', '', 1, datetime('now')),
            (201, 'writer', '', 2, datetime('now'))");
        foreach ([
            101 => ['documents:read'],
            102 => ['documents:read', 'documents:write', 'documents:comment', 'documents:suggest'],
            103 => ['documents:read', 'documents:suggest', 'documents:comment'],
            201 => ['documents:read', 'documents:write', 'documents:suggest', 'documents:comment'],
        ] as $role => $permissions) {
            foreach ($permissions as $permission) {
                $pdo->prepare('INSERT OR IGNORE INTO permissions (name, description, created_at) VALUES (?, ?, NOW())')->execute([$permission, '']);
                $sel = $pdo->prepare('SELECT id FROM permissions WHERE name = ?');
                $sel->execute([$permission]);
                $pdo->prepare('INSERT OR IGNORE INTO role_permissions (role_id, permission_id, created_at) VALUES (?, ?, NOW())')
                    ->execute([$role, (int) $sel->fetchColumn()]);
            }
        }
        $pdo->exec("
            INSERT INTO profiles (id, display_name, password_hash, two_factor_enabled, two_factor_backup_codes_version, token_epoch, created_at, updated_at) VALUES
                (11, 'viewer',  'x', false, 0, 0, CURRENT_TIMESTAMP, CURRENT_TIMESTAMP),
                (12, 'writer',  'x', false, 0, 0, CURRENT_TIMESTAMP, CURRENT_TIMESTAMP),
                (13, 'other',   'x', false, 0, 0, CURRENT_TIMESTAMP, CURRENT_TIMESTAMP),
                (20, 'foreign', 'x', false, 0, 0, CURRENT_TIMESTAMP, CURRENT_TIMESTAMP)
        ");
        $pdo->exec("
            INSERT INTO memberships (id, profile_id, tenant_id, role_id, status, created_at) VALUES
                (1001, 11, 1, 101, 'active', datetime('now')),
                (1002, 12, 1, 102, 'active', datetime('now')),
                (1003, 13, 1, 103, 'active', datetime('now')),
                (2000, 20, 2, 201, 'active', datetime('now'))
        ");
        $pdo->exec("INSERT INTO mcp_tokens (jti, profile_id, tenant_id, name, principal_kind, scope, expires_at, created_at) VALUES
            ('agent-jti-1', 12, 1, 'Billing assistant', 'ai-agent', '[]', '2099-01-01 00:00:00', CURRENT_TIMESTAMP),
            ('agent-jti-2', 13, 1, 'Other assistant', 'ai-agent', '[]', '2099-01-01 00:00:00', CURRENT_TIMESTAMP)");

        return $pdo;
    }

    private function wrap(PDO $pdo): \Whity\Database\Database
    {
        $db = \Whity\Database\Database::withFactory(static fn (): PDO => $pdo);
        $db->setMaxLifetimeSeconds(86400);
        $db->setPingIntervalSeconds(86400);
        $db->forceConnect();

        return $db;
    }
}
