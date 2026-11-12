<?php

declare(strict_types=1);

namespace Tests\Integration;

use PDO;
use PHPUnit\Framework\TestCase;
use Tests\Support\SchemaFromMigrations;
use Whity\Api\ChatApiHandler;
use Whity\Core\Chat\ChatRepository;
use Whity\Core\Chat\ChatService;
use Whity\Core\Messaging\ConversationRepository;
use Whity\Core\Request;
use Whity\Core\Tenant\TenantContext;
use Whity\Mcp\Auth\McpPrincipal;

/**
 * Team chat on the real schema (CH.1, CH.2; migration 253): only members see a
 * chat, an agent reads only from when it joined and never another chat, its
 * joining line says who added it and what it may do, a held agent line waits
 * for the person it works for, a shared proposal is decided only by its person,
 * and team chats never appear in the customer Messages inbox.
 */
final class ChatRealEngineTest extends TestCase
{
    private const A = 1;
    private const B = 2;
    private const AMRO = 10;
    private const AHMAD = 11;
    private const EMAD = 12;
    private const OTHER_WORKSPACE = 13;
    private const CUSTOMER = 14;

    private PDO $pdo;
    private ChatApiHandler $handler;
    private int $stockAgent = 0;
    private int $billingAgent = 0;
    /** Stock assistant working for Ahmad. */
    private int $stockForAhmad = 0;
    /** Billing assistant working for Amro. */
    private int $billingForAmro = 0;

    protected function setUp(): void
    {
        TenantContext::reset();
        $this->pdo = SchemaFromMigrations::make(true);
        $this->seed();
        $this->handler = new ChatApiHandler(new ChatService(new ChatRepository($this->pdo)));
        TenantContext::setTenantId(self::A);
    }

    protected function tearDown(): void
    {
        TenantContext::reset();
    }

    public function testOnlyMembersSeeAGroupAndJoiningLinesAreWritten(): void
    {
        $chat = $this->ok($this->handler->create($this->person(self::AMRO, ['type' => 'group', 'title' => 'Ramadan boxes', 'people' => [self::AHMAD],
            'link' => ['type' => 'board', 'id' => '7', 'label' => 'Ramadan boxes']])), 201);
        $id = (string) $chat['id'];
        self::assertSame('group', $chat['type']);
        self::assertSame(['type' => 'board', 'id' => '7', 'label' => 'Ramadan boxes'], $chat['link']);
        self::assertCount(2, $chat['members']);
        self::assertSame('system', $chat['messages'][0]['kind']);

        // Emad is staff but not in the chat: it does not exist for him.
        self::assertSame(404, $this->handler->show($this->person(self::EMAD), ['id' => $id])->getStatusCode());
        self::assertSame([], $this->ok($this->handler->list($this->person(self::EMAD))));
        self::assertSame(404, $this->handler->post($this->person(self::EMAD, ['body' => 'hi']), ['id' => $id])->getStatusCode());

        // A customer of the workspace is not staff.
        self::assertSame(403, $this->handler->list($this->person(self::CUSTOMER))->getStatusCode());

        // Another workspace never sees it.
        TenantContext::reset();
        TenantContext::setTenantId(self::B);
        self::assertSame(404, $this->handler->show($this->person(self::OTHER_WORKSPACE), ['id' => $id])->getStatusCode());
        TenantContext::reset();
        TenantContext::setTenantId(self::A);

        $posted = $this->ok($this->handler->post($this->person(self::AHMAD, ['body' => 'How many gift boxes for the first week?']), ['id' => $id]), 201);
        self::assertSame(['type' => 'person', 'profile_id' => self::AHMAD, 'name' => 'Ahmad Khalil'], $posted['author']);

        $list = $this->ok($this->handler->list($this->person(self::AMRO)));
        self::assertCount(1, $list);
        self::assertSame(1, $list[0]['unread'], 'Ahmad\'s line is unread for Amro; Amro\'s own system line is not');
        self::assertSame('How many gift boxes for the first week?', $list[0]['last_message']['body']);
    }

    public function testAnAgentReadsOnlyFromWhenItJoinedAndNeverOtherChats(): void
    {
        $chat = $this->ok($this->handler->create($this->person(self::AHMAD, ['type' => 'group', 'title' => 'Ramadan boxes', 'people' => [self::AMRO]])), 201);
        $id = (string) $chat['id'];
        $this->ok($this->handler->post($this->person(self::AHMAD, ['body' => 'Before the agent joined']), ['id' => $id]), 201);

        // An agent can only work for someone who set it up.
        $refused = $this->handler->addMember($this->person(self::AHMAD, ['agent_id' => $this->stockAgent, 'works_for' => self::AMRO]), ['id' => $id]);
        self::assertSame(422, $refused->getStatusCode());

        $added = $this->ok($this->handler->addMember($this->person(self::AHMAD, [
            'agent_id' => $this->stockAgent, 'works_for' => self::AHMAD, 'reads' => 'now', 'actions' => ['post' => 'allowed', 'order_stock' => 'ask'],
        ]), ['id' => $id]), 201);
        $agentMember = array_values(array_filter($added['members'], static fn (array $m): bool => $m['type'] === 'agent'))[0];
        self::assertSame(['id' => self::AHMAD, 'name' => 'Ahmad Khalil'], $agentMember['works_for']);
        self::assertEquals(['order_stock' => 'ask', 'post' => 'allowed'], $agentMember['actions']);
        $addedLines = $added['messages'];
        $joining = end($addedLines);
        self::assertSame('system', $joining['kind']);
        self::assertSame('agent_added', $joining['event']['event']);
        self::assertSame('Ahmad Khalil', $joining['event']['by']['name']);
        self::assertSame('now', $joining['event']['reads']);
        self::assertStringContainsString('reads this chat from now on', $joining['body']);

        // A second chat the agent is not in.
        $other = $this->ok($this->handler->create($this->person(self::AHMAD, ['type' => 'group', 'title' => 'Payroll', 'people' => []])), 201);

        $agent = $this->agent($this->stockForAhmad, self::AHMAD);
        $seen = $this->ok($this->handler->show($agent, ['id' => $id]));
        $bodies = array_column($seen['messages'], 'body');
        self::assertNotContains('Before the agent joined', $bodies, 'reads from when it joined');
        self::assertCount(1, $seen['messages'], 'only its own joining line');
        self::assertSame(404, $this->handler->show($this->agent($this->stockForAhmad, self::AHMAD), ['id' => (string) $other['id']])->getStatusCode());
        $older = $this->ok($this->handler->messages($this->agent($this->stockForAhmad, self::AHMAD, 'GET', '/api/chat/chats/' . $id . '/messages?after=0'), ['id' => $id]));
        self::assertNotContains('Before the agent joined', array_column($older, 'body'));

        $chats = $this->ok($this->handler->list($this->agent($this->stockForAhmad, self::AHMAD)));
        self::assertSame([(int) $id], array_column($chats, 'id'), 'the agent lists only its chat');

        // The same agent for another person is not this member: no chats.
        self::assertSame([], $this->ok($this->handler->list($this->agent($this->stockForAmroUnset(), self::AMRO))));

        // An agent posts, marked as an agent working for Ahmad; mentions find members.
        $posted = $this->ok($this->handler->post($this->agent($this->stockForAhmad, self::AHMAD, 'POST', '/x', [
            'body' => '120 at the Central kitchen.', 'mentions' => [['type' => 'person', 'id' => self::AMRO], ['type' => 'person', 'id' => self::EMAD]],
        ]), ['id' => $id]), 201);
        self::assertSame('agent', $posted['author']['type']);
        self::assertSame('Stock assistant', $posted['author']['name']);
        self::assertSame(['id' => self::AHMAD, 'name' => 'Ahmad Khalil'], $posted['author']['works_for']);
        self::assertSame([['type' => 'person', 'id' => self::AMRO, 'name' => 'Amro Saleh']], $posted['mentions'], 'Emad is not in the chat');

        // Agents cannot change chats.
        self::assertSame(403, $this->handler->addMember($this->agent($this->stockForAhmad, self::AHMAD, 'POST', '/x', ['profile_id' => self::EMAD]), ['id' => $id])->getStatusCode());

        // Removed: it sees nothing.
        $this->ok($this->handler->removeMember($this->person(self::AMRO, [], 'DELETE'), ['id' => $id, 'member' => (string) $agentMember['member_id']]));
        self::assertSame(404, $this->handler->show($this->agent($this->stockForAhmad, self::AHMAD), ['id' => $id])->getStatusCode());
    }

    public function testHeldAgentLinesAndSharedProposalsWaitForTheirPerson(): void
    {
        $chat = $this->ok($this->handler->create($this->person(self::AMRO, ['type' => 'group', 'title' => 'Billing', 'people' => [self::AHMAD]])), 201);
        $id = (string) $chat['id'];
        $this->ok($this->handler->addMember($this->person(self::AMRO, [
            'agent_id' => $this->billingAgent, 'works_for' => self::AMRO, 'reads' => 'all', 'actions' => ['post' => 'ask'],
        ]), ['id' => $id]), 201);

        $held = $this->ok($this->handler->post($this->agent($this->billingForAmro, self::AMRO, 'POST', '/x', ['body' => 'Three reminders are ready.']), ['id' => $id]), 201);
        self::assertSame('held', $held['status']);
        self::assertSame(['id' => self::AMRO, 'name' => 'Amro Saleh'], $held['waiting_for']);

        $asAhmad = $this->ok($this->handler->show($this->person(self::AHMAD), ['id' => $id]));
        $lines = $asAhmad['messages'];
        $line = end($lines);
        self::assertFalse($line['can_decide']);
        self::assertSame(403, $this->handler->approve($this->person(self::AHMAD, [], 'POST'), ['id' => (string) $held['id']])->getStatusCode());
        $approved = $this->ok($this->handler->approve($this->person(self::AMRO, [], 'POST'), ['id' => (string) $held['id']]));
        self::assertSame('posted', $approved['status']);
        self::assertSame(409, $this->handler->approve($this->person(self::AMRO, [], 'POST'), ['id' => (string) $held['id']])->getStatusCode());

        // A proposal the agent made for Amro, shown in the chat.
        $this->pdo->exec("INSERT INTO agent_proposals (tenant_id, agent_principal_id, on_behalf_of_profile_id, tool, summary, status, expires_at, created_at)
            VALUES (" . self::A . ", 'agent:{$this->billingForAmro}', " . self::AMRO . ", 'post_api_invoices', 'Purchase order: 300 gift boxes', 'waiting', '2099-01-01 00:00:00', CURRENT_TIMESTAMP)");
        $proposalId = $this->scalar('SELECT MAX(id) FROM agent_proposals');

        // Another agent's proposal cannot be shared.
        self::assertSame(404, $this->handler->shareProposal($this->agent($this->billingForAmro, self::AMRO, 'POST', '/x', ['proposal_id' => $proposalId + 99]), ['id' => $id])->getStatusCode());
        $card = $this->ok($this->handler->shareProposal($this->agent($this->billingForAmro, self::AMRO, 'POST', '/x', ['proposal_id' => $proposalId]), ['id' => $id]), 201);
        self::assertSame('proposal', $card['kind']);
        self::assertSame('waiting', $card['proposal']['status']);
        self::assertFalse($card['proposal']['can_decide'], 'the agent never decides');

        $amroView = $this->ok($this->handler->show($this->person(self::AMRO), ['id' => $id]))['messages'];
        $forAmro = end($amroView);
        self::assertTrue($forAmro['proposal']['can_decide']);
        $ahmadView = $this->ok($this->handler->show($this->person(self::AHMAD), ['id' => $id]))['messages'];
        $forAhmad = end($ahmadView);
        self::assertFalse($forAhmad['proposal']['can_decide']);
        self::assertSame('Amro Saleh', $forAhmad['proposal']['waiting_for']['name']);
    }

    public function testDirectChatsAndTheCustomerInboxStaysSeparate(): void
    {
        $first = $this->ok($this->handler->create($this->person(self::AMRO, ['type' => 'direct', 'profile_id' => self::AHMAD])), 201);
        $again = $this->ok($this->handler->create($this->person(self::AHMAD, ['type' => 'direct', 'profile_id' => self::AMRO])), 201);
        self::assertSame($first['id'], $again['id'], 'one direct chat per pair');
        self::assertSame('Ahmad Khalil', $first['title']);
        self::assertSame('Amro Saleh', $again['title']);

        // Adding a third person makes it a group.
        $grown = $this->ok($this->handler->addMember($this->person(self::AMRO, ['profile_id' => self::EMAD]), ['id' => (string) $first['id']]), 201);
        self::assertSame('group', $grown['type']);

        // A direct chat with one's own agent.
        $withAgent = $this->ok($this->handler->create($this->person(self::AMRO, ['type' => 'direct', 'agent_id' => $this->billingAgent])), 201);
        self::assertSame('Billing assistant', $withAgent['title']);
        self::assertSame(422, $this->handler->create($this->person(self::EMAD, ['type' => 'direct', 'agent_id' => $this->billingAgent]))->getStatusCode());

        $conversations = new ConversationRepository($this->pdo);
        self::assertSame([], $conversations->list(self::A, [], null, 50), 'team chats are not customer conversations');
        self::assertSame(0, $conversations->count(self::A, []));
        self::assertNull($conversations->find(self::A, (int) $first['id']));
    }

    // ── Helpers ──────────────────────────────────────────────────────────────

    /** @param array<string, mixed> $body */
    private function person(int $profileId, array $body = [], string $method = 'GET', string $path = '/x'): Request
    {
        $method = $body !== [] && $method === 'GET' ? 'POST' : $method;
        $request = new Request($method, $path, ['Content-Type' => 'application/json'], $body === [] ? '' : (string) json_encode($body));
        $request->user = (object) ['profile_id' => $profileId];

        return $request;
    }

    /** @param array<string, mixed> $body */
    private function agent(int $assignmentId, int $profileId, string $method = 'GET', string $path = '/x', array $body = []): Request
    {
        $request = new Request($method, $path, ['Content-Type' => 'application/json'], $body === [] ? '' : (string) json_encode($body));
        $request->user = (object) [
            'profile_id' => $profileId,
            'principal_kind' => McpPrincipal::KIND_AGENT,
            'agent_principal_id' => 'agent:' . $assignmentId,
        ];

        return $request;
    }

    /** An assignment of Stock assistant for Amro, who has no chat with it. */
    private function stockForAmroUnset(): int
    {
        $this->pdo->exec('INSERT INTO agent_assignments (tenant_id, agent_id, profile_id, created_at, updated_at) VALUES ('
            . self::A . ', ' . $this->stockAgent . ', ' . self::AMRO . ', CURRENT_TIMESTAMP, CURRENT_TIMESTAMP)');

        return $this->scalar('SELECT MAX(id) FROM agent_assignments');
    }

    private function scalar(string $sql): int
    {
        $stmt = $this->pdo->prepare($sql);
        $stmt->execute();

        return (int) $stmt->fetchColumn();
    }

    /** @return mixed the response's data, after checking its status */
    private function ok(\Whity\Sdk\Http\Response $response, int $status = 200): mixed
    {
        $body = json_decode((string) $response->getBody(), true);
        self::assertSame($status, $response->getStatusCode(), (string) $response->getBody());

        return $body['data'];
    }

    private function seed(): void
    {
        $pdo = $this->pdo;
        $pdo->exec("INSERT OR IGNORE INTO tenants (id, name, slug) VALUES (0, 'system', 'system')");
        $pdo->exec("INSERT INTO tenants (id, name, slug) VALUES (" . self::A . ", 'Example Bakery', 'bakery'), (" . self::B . ", 'Example Florist', 'florist')");
        $role = $this->scalar("SELECT id FROM roles WHERE name = 'user' AND tenant_id IS NULL");
        self::assertGreaterThan(0, $role);

        $people = [self::AMRO => 'Amro Saleh', self::AHMAD => 'Ahmad Khalil', self::EMAD => 'Emad Nasser', self::OTHER_WORKSPACE => 'Awss Haddad', self::CUSTOMER => 'Ibrahim Mansour'];
        foreach ($people as $id => $name) {
            $pdo->exec("INSERT INTO profiles (id, display_name, password_hash, two_factor_enabled, two_factor_backup_codes_version, token_epoch, created_at, updated_at)
                        VALUES ({$id}, '{$name}', 'x', false, 0, 0, CURRENT_TIMESTAMP, CURRENT_TIMESTAMP)");
        }
        $pdo->exec("INSERT INTO memberships (profile_id, tenant_id, role_id, status, kind, created_at) VALUES
            (" . self::AMRO . ", " . self::A . ", {$role}, 'active', 'member', CURRENT_TIMESTAMP),
            (" . self::AHMAD . ", " . self::A . ", {$role}, 'active', 'member', CURRENT_TIMESTAMP),
            (" . self::EMAD . ", " . self::A . ", {$role}, 'active', 'member', CURRENT_TIMESTAMP),
            (" . self::OTHER_WORKSPACE . ", " . self::B . ", {$role}, 'active', 'member', CURRENT_TIMESTAMP),
            (" . self::CUSTOMER . ", " . self::A . ", {$role}, 'active', 'customer', CURRENT_TIMESTAMP)");

        $pdo->exec("INSERT INTO agents (tenant_id, slug, name, role, status, created_at, updated_at) VALUES
            (" . self::A . ", 'stock', 'Stock assistant', 'Counts and orders stock', 'active', CURRENT_TIMESTAMP, CURRENT_TIMESTAMP),
            (" . self::A . ", 'billing', 'Billing assistant', 'Invoices, reminders and payments', 'active', CURRENT_TIMESTAMP, CURRENT_TIMESTAMP)");
        $this->stockAgent = $this->scalar("SELECT id FROM agents WHERE slug = 'stock'");
        $this->billingAgent = $this->scalar("SELECT id FROM agents WHERE slug = 'billing'");

        $pdo->exec('INSERT INTO agent_assignments (tenant_id, agent_id, profile_id, created_at, updated_at) VALUES ('
            . self::A . ', ' . $this->stockAgent . ', ' . self::AHMAD . ', CURRENT_TIMESTAMP, CURRENT_TIMESTAMP)');
        $this->stockForAhmad = $this->scalar('SELECT MAX(id) FROM agent_assignments');
        $pdo->exec('INSERT INTO agent_assignments (tenant_id, agent_id, profile_id, created_at, updated_at) VALUES ('
            . self::A . ', ' . $this->billingAgent . ', ' . self::AMRO . ', CURRENT_TIMESTAMP, CURRENT_TIMESTAMP)');
        $this->billingForAmro = $this->scalar('SELECT MAX(id) FROM agent_assignments');

        SchemaFromMigrations::syncSequences($pdo);
    }
}
