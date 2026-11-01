<?php

declare(strict_types=1);

namespace Tests\Api;

use PDO;
use PHPUnit\Framework\TestCase;
use Tests\Support\SchemaFromMigrations;
use Whity\Api\DocumentCollabApiHandler;
use Whity\Api\DocumentPublishingApiHandler;
use Whity\Api\DocumentSourcesApiHandler;
use Whity\Auth\RoleChecker;
use Whity\Core\Audit\Actor;
use Whity\Core\Audit\AuditContext;
use Whity\Core\Audit\AuditLogger;
use Whity\Core\Document\Collab\DocumentCollab;
use Whity\Core\Document\Comment\DocumentCommentRepository;
use Whity\Core\Document\DocumentAccessPolicy;
use Whity\Core\Document\DocumentSourceRepository;
use Whity\Core\Document\DocumentSourceVersionRepository;
use Whity\Core\Document\Embed\DocumentEmbedRegistry;
use Whity\Core\Document\Fields\DocumentFieldRegistry;
use Whity\Core\Document\Suggestion\DocumentSuggestionRepository;
use Whity\Core\Notification\CoreTransports;
use Whity\Core\Notification\NotificationDispatcher;
use Whity\Core\Notification\NotificationRepository;
use Whity\Core\Ou\OuReachResolver;
use Whity\Core\Queue\JobRepository;
use Whity\Core\Queue\QueueService;
use Whity\Core\RBAC\PermissionRegistry;
use Whity\Core\RBAC\ResourceRoleAssignmentRepository;
use Whity\Core\RBAC\ResourceTypeRegistry;
use Whity\Core\Request;
use Whity\Core\Settings\GlobalSettingsRepository;
use Whity\Core\Settings\SettingsService;
use Whity\Core\Settings\TenantSettingsRepository;
use Whity\Core\Tenant\TenantContext;
use Whity\Database\Database;
use Whity\Sdk\Http\Response;

/**
 * Real-engine tests for comments and suggestions (editor M5,
 * {@see DocumentCollabApiHandler}). Runs on SQLite by default and on
 * PostgreSQL when PHPUNIT_PG_DSN is set (Tests\Support\SchemaFromMigrations).
 *
 * What matters: suggesting needs only documents:suggest and never touches the
 * draft; deciding needs documents:write, is a person's, applies the patch and
 * bumps the draft version, and goes stale (409) when its text has gone; a
 * document the caller can't see answers 404 everywhere; mentions tell the
 * people who can read it; open suggestions block publishing; and a draft
 * save can't smuggle a suggestion in.
 */
final class DocumentCollabApiRealEngineTest extends TestCase
{
    private const TENANT = 1;
    private const OTHER_TENANT = 2;

    private const OWNER = 10;     // admin: read, write, publish, and (migration 186) suggest and comment
    private const VIEWER = 11;    // read only
    private const WRITER = 12;    // read, write, comment
    private const SUGGESTER = 13; // read, suggest, comment; no write
    private const FOREIGN = 20;   // read, write, suggest, comment in the other tenant

    private PDO $pdo;
    private DocumentCollabApiHandler $handler;
    private DocumentSourcesApiHandler $sources;
    private DocumentPublishingApiHandler $publishing;
    private DocumentSourceRepository $repo;

    protected function setUp(): void
    {
        RoleChecker::clearCache();
        AuditContext::reset();
        $this->pdo = $this->makeSchema();
        $roleChecker = new RoleChecker($this->wrap($this->pdo), new PermissionRegistry());
        $resourceRoles = new ResourceRoleAssignmentRepository($this->pdo, new ResourceTypeRegistry());
        $this->repo = new DocumentSourceRepository($this->pdo);
        $collab = new DocumentCollab(
            $this->pdo,
            $this->repo,
            new DocumentSuggestionRepository($this->pdo),
            new DocumentCommentRepository($this->pdo),
        );
        $this->sources = new DocumentSourcesApiHandler(
            $this->repo,
            new DocumentAccessPolicy(),
            $roleChecker,
            new OuReachResolver($this->pdo, $resourceRoles),
            collab: $collab,
        );
        $this->handler = new DocumentCollabApiHandler(
            $this->sources,
            $collab,
            $roleChecker,
            new AuditLogger($this->pdo),
            new NotificationDispatcher(
                new NotificationRepository($this->pdo),
                CoreTransports::make(),
                new QueueService(new JobRepository($this->pdo)),
            ),
        );
        $this->publishing = new DocumentPublishingApiHandler(
            $this->sources,
            new DocumentSourceVersionRepository($this->pdo),
            new DocumentFieldRegistry(),
            new DocumentEmbedRegistry(),
            $roleChecker,
            new SettingsService(new GlobalSettingsRepository($this->pdo), new TenantSettingsRepository($this->pdo)),
            collab: $collab,
        );
    }

    protected function tearDown(): void
    {
        TenantContext::reset();
        AuditContext::reset();
        RoleChecker::clearCache();
    }

    // ── permissions ──────────────────────────────────────────────────────

    public function testMigration186GrantsSuggestAndCommentToReaders(): void
    {
        $held = $this->q(
            "SELECT p.name FROM role_permissions rp JOIN permissions p ON p.id = rp.permission_id
              JOIN roles r ON r.id = rp.role_id WHERE r.name = 'admin' AND p.name IN ('documents:suggest', 'documents:comment') ORDER BY p.name"
        )->fetchAll(PDO::FETCH_COLUMN);

        self::assertSame(['documents:comment', 'documents:suggest'], $held, 'the admin role held documents:read when 186 ran');
    }

    public function testSuggestingNeedsSuggestNotWriteAndLeavesTheDraftAlone(): void
    {
        $id = $this->document();

        $made = $this->suggest(self::SUGGESTER, $id, $this->rewrite());
        self::assertSame(201, $made->getStatusCode(), $made->getBody());
        $s = $this->data($made);
        self::assertSame('open', $s['status']);
        self::assertSame('text', $s['kind']);
        self::assertSame('documents:suggest', $s['permission']);
        self::assertSame(1, $s['base_version']);
        self::assertSame(['kind' => 'person', 'id' => self::SUGGESTER, 'name' => 'suggester'], $s['by']);
        self::assertMatchesRegularExpression('/^\d{4}-\d\d-\d\dT\d\d:\d\d:\d\dZ$/', $s['at']);

        $doc = $this->data($this->show(self::SUGGESTER, $id));
        self::assertSame(1, $doc['draft_version'], 'a suggestion never writes the draft');
        self::assertSame('Pay within 30 days.', $doc['blocks'][0]['content'][0]['text']);
        self::assertSame([$s['id']], array_column($doc['suggestions'], 'id'), 'GET /{id} carries the open suggestions');
        self::assertSame([], $doc['comments']);

        $refused = $this->suggest(self::VIEWER, $id, $this->rewrite());
        self::assertSame(403, $refused->getStatusCode(), 'read alone does not suggest');
    }

    public function testTheDecisionNeedsWrite(): void
    {
        $id = $this->document();
        $sid = (int) $this->data($this->suggest(self::SUGGESTER, $id, $this->rewrite()))['id'];

        self::assertSame(403, $this->decide(self::SUGGESTER, $id, $sid, 'accepted')->getStatusCode(), 'suggest does not decide');
        self::assertSame(403, $this->decideMany(self::SUGGESTER, $id, ['decision' => 'accepted'])->getStatusCode());
        self::assertSame(200, $this->decide(self::WRITER, $id, $sid, 'rejected')->getStatusCode());
    }

    public function testCommentingNeedsComment(): void
    {
        $id = $this->document();

        self::assertSame(403, $this->comment(self::VIEWER, $id, 'b1', 'within', 'Why?')->getStatusCode());
        self::assertSame(201, $this->comment(self::SUGGESTER, $id, 'b1', 'within', 'Why?')->getStatusCode());
        self::assertSame(200, $this->get('listComments', self::VIEWER, $id)->getStatusCode(), 'reading needs read only');
    }

    // ── visibility and tenants ───────────────────────────────────────────

    public function testADocumentTheCallerCannotSeeAnswers404(): void
    {
        $personal = $this->document(self::WRITER, 'personal');
        $sid = (int) $this->data($this->suggest(self::WRITER, $personal, $this->rewrite()))['id'];
        $tid = (int) $this->data($this->comment(self::WRITER, $personal, 'b1', 'Pay', 'Mine'))['id'];

        self::assertSame(404, $this->suggest(self::SUGGESTER, $personal, $this->rewrite())->getStatusCode());
        self::assertSame(404, $this->get('listSuggestions', self::SUGGESTER, $personal)->getStatusCode());
        self::assertSame(404, $this->get('listComments', self::SUGGESTER, $personal)->getStatusCode());
        self::assertSame(404, $this->get('changes', self::SUGGESTER, $personal)->getStatusCode());
        self::assertSame(404, $this->comment(self::SUGGESTER, $personal, 'b1', 'Pay', 'Hi')->getStatusCode());
        self::assertSame(404, $this->reply(self::SUGGESTER, $personal, $tid, 'Hi')->getStatusCode());
        self::assertSame(404, $this->decide(self::OWNER, $personal, $sid, 'accepted')->getStatusCode(), 'even with write');
    }

    public function testAnotherTenantSeesNothing(): void
    {
        $id = $this->document();
        $sid = (int) $this->data($this->suggest(self::SUGGESTER, $id, $this->rewrite()))['id'];
        $tid = (int) $this->data($this->comment(self::SUGGESTER, $id, 'b1', 'Pay', 'Hm'))['id'];

        self::assertSame(404, $this->get('listSuggestions', self::FOREIGN, $id, '', self::OTHER_TENANT)->getStatusCode());
        self::assertSame(404, $this->decide(self::FOREIGN, $id, $sid, 'accepted', self::OTHER_TENANT)->getStatusCode());
        self::assertSame(404, $this->reply(self::FOREIGN, $id, $tid, 'x', self::OTHER_TENANT)->getStatusCode());

        // The other tenant's own document doesn't reach this one's rows either.
        $foreignDoc = $this->document(self::FOREIGN, 'tenant', self::OTHER_TENANT);
        self::assertSame(404, $this->decide(self::FOREIGN, $foreignDoc, $sid, 'accepted', self::OTHER_TENANT)->getStatusCode(), 'a suggestion id is bound to its document and tenant');
        self::assertSame(404, $this->reply(self::FOREIGN, $foreignDoc, $tid, 'x', self::OTHER_TENANT)->getStatusCode());
        self::assertSame('open', $this->data($this->get('listSuggestions', self::SUGGESTER, $id))[0]['status']);
    }

    // ── decisions ────────────────────────────────────────────────────────

    public function testAcceptingAppliesThePatchAndBumpsTheDraftVersion(): void
    {
        $id = $this->document();
        $sid = (int) $this->data($this->suggest(self::SUGGESTER, $id, $this->rewrite()))['id'];

        $res = $this->decide(self::WRITER, $id, $sid, 'accepted');
        self::assertSame(200, $res->getStatusCode(), $res->getBody());
        $out = $this->data($res);
        self::assertSame(2, $out['draft_version']);
        self::assertSame('"2"', self::header($res, 'ETag'));
        self::assertSame('accepted', $out['suggestion']['status']);
        self::assertSame(['kind' => 'person', 'id' => self::WRITER, 'name' => 'writer'], $out['suggestion']['decided_by']);

        $doc = $this->data($this->show(self::WRITER, $id));
        self::assertSame(2, $doc['draft_version']);
        self::assertSame('Pay within 14 days.', $doc['blocks'][0]['content'][0]['text']);
        self::assertSame([], $doc['suggestions'], 'no longer open');

        $again = $this->decide(self::WRITER, $id, $sid, 'rejected');
        self::assertSame(409, $again->getStatusCode());
        self::assertSame('already_decided', $this->details($again)['code']);

        self::assertSame(
            ['documents.suggestion.accepted'],
            $this->q("SELECT action FROM audit_log WHERE action LIKE 'documents.suggestion.a%' AND actor_user_id = " . self::WRITER)->fetchAll(PDO::FETCH_COLUMN),
        );
    }

    public function testAStaleSuggestionAnswers409(): void
    {
        $id = $this->document();
        $sid = (int) $this->data($this->suggest(self::SUGGESTER, $id, $this->rewrite()))['id'];

        // Someone edits the text away.
        $saved = $this->save(self::WRITER, $id, '"1"', ['blocks' => [['id' => 'b1', 'type' => 'paragraph', 'content' => [['type' => 'text', 'text' => 'Pay on receipt.']]]]]);
        self::assertSame(200, $saved->getStatusCode(), $saved->getBody());

        $res = $this->decide(self::WRITER, $id, $sid, 'accepted');
        self::assertSame(409, $res->getStatusCode());
        self::assertSame('suggestion_stale', $this->details($res)['code']);
        self::assertSame('stale', $this->data($this->get('listSuggestions', self::WRITER, $id, '?status=all'))[0]['status']);
        self::assertSame(2, $this->data($this->show(self::WRITER, $id))['draft_version'], 'the draft is untouched');

        $again = $this->decide(self::WRITER, $id, $sid, 'accepted');
        self::assertSame('suggestion_stale', $this->details($again)['code']);
    }

    public function testASuggestionThatDoesNotApplyIsRefused(): void
    {
        $id = $this->document();

        $res = $this->suggest(self::SUGGESTER, $id, ['kind' => 'text', 'summary' => 'x', 'patch' => [
            'op' => 'replace-text', 'blockId' => 'b1', 'deleteText' => '60 days', 'occurrence' => 0, 'insert' => [],
        ]]);
        self::assertSame(422, $res->getStatusCode());
        self::assertSame('patch_does_not_apply', $this->details($res)['code']);

        $wrongKind = $this->suggest(self::SUGGESTER, $id, ['kind' => 'block'] + $this->rewrite());
        self::assertSame(422, $wrongKind->getStatusCode());
    }

    public function testAgentsSuggestAsThemselvesForThePersonButNeverDecide(): void
    {
        $id = $this->document();
        $this->pdo->exec("INSERT INTO mcp_tokens (jti, profile_id, tenant_id, name, principal_kind, scope, expires_at, created_at)
            VALUES ('agent-jti-1', 12, 1, 'Billing assistant', 'ai-agent', '[]', '2099-01-01 00:00:00', CURRENT_TIMESTAMP)");

        AuditContext::setActor(Actor::agent('agent-jti-1', self::WRITER));
        $made = $this->suggest(self::WRITER, $id, $this->rewrite());
        self::assertSame(201, $made->getStatusCode(), $made->getBody());
        self::assertSame(
            ['kind' => 'agent', 'id' => 'agent-jti-1', 'name' => 'Billing assistant', 'for' => ['id' => self::WRITER, 'name' => 'writer']],
            $this->data($made)['by'],
        );
        $audit = $this->q("SELECT actor_kind, agent_principal_id, on_behalf_of_profile_id FROM audit_log WHERE action = 'documents.suggestion.created'")->fetch(PDO::FETCH_ASSOC);
        self::assertSame(['actor_kind' => 'agent', 'agent_principal_id' => 'agent-jti-1', 'on_behalf_of_profile_id' => '12'], array_map('strval', (array) $audit));

        $sid = (int) $this->data($made)['id'];
        self::assertSame(403, $this->decide(self::WRITER, $id, $sid, 'accepted')->getStatusCode(), 'an agent never decides, even for a writer');
        self::assertSame(403, $this->decideMany(self::WRITER, $id, ['decision' => 'accepted'])->getStatusCode());

        AuditContext::reset();
        self::assertSame(200, $this->decide(self::WRITER, $id, $sid, 'accepted')->getStatusCode(), 'the person does');
    }

    public function testTheBulkDecisionByAgent(): void
    {
        $id = $this->document();
        AuditContext::setActor(Actor::agent('agent-a', self::WRITER));
        $a1 = (int) $this->data($this->suggest(self::WRITER, $id, $this->rewrite()))['id'];
        $a2 = (int) $this->data($this->suggest(self::WRITER, $id, ['kind' => 'block', 'summary' => 'Add a note', 'patch' => [
            'op' => 'insert-blocks', 'afterBlockId' => 'b1', 'blocks' => [['id' => 'n1', 'type' => 'paragraph', 'content' => [['type' => 'text', 'text' => 'Thank you.']]]],
        ]]))['id'];
        // Applies now, but goes stale once a1 replaces "30".
        $a3 = (int) $this->data($this->suggest(self::WRITER, $id, ['kind' => 'format', 'summary' => 'Bold 30', 'patch' => [
            'op' => 'format', 'blockId' => 'b1', 'deleteText' => '', 'text' => '30', 'occurrence' => 0, 'mark' => ['type' => 'bold'], 'add' => true,
        ]]))['id'];
        AuditContext::setActor(Actor::agent('agent-b', self::WRITER));
        $b1 = (int) $this->data($this->suggest(self::WRITER, $id, ['kind' => 'block', 'summary' => 'Delete heading', 'patch' => [
            'op' => 'delete-blocks', 'blockIds' => ['h1'],
        ]]))['id'];
        AuditContext::reset();
        $person = (int) $this->data($this->suggest(self::SUGGESTER, $id, ['kind' => 'block', 'summary' => 'Move', 'patch' => [
            'op' => 'move-block', 'blockId' => 'h1', 'afterBlockId' => 'b1',
        ]]))['id'];

        $res = $this->decideMany(self::WRITER, $id, ['decision' => 'accepted', 'agent' => 'agent-a']);
        self::assertSame(200, $res->getStatusCode(), $res->getBody());
        self::assertSame(['decided' => [$a1, $a2], 'stale' => [$a3], 'draft_version' => 2], $this->data($res), 'one bump for the batch');

        $doc = $this->data($this->show(self::WRITER, $id));
        self::assertSame(['b1', 'n1', 'h1'], array_column($doc['blocks'], 'id'));
        self::assertSame('Pay within 14 days.', $doc['blocks'][0]['content'][0]['text']);
        self::assertSame([$b1, $person], array_column($doc['suggestions'], 'id'), 'other agents\' and people\'s stay open');

        $rejected = $this->decideMany(self::WRITER, $id, ['decision' => 'rejected', 'suggestion_ids' => [$person]]);
        self::assertSame(['decided' => [$person], 'stale' => [], 'draft_version' => 2], $this->data($rejected));
        self::assertSame(3, (int) $this->q("SELECT COUNT(*) FROM audit_log WHERE action IN ('documents.suggestion.accepted', 'documents.suggestion.rejected')")->fetchColumn(), 'a1, a2 and the rejection');
    }

    // ── comments ─────────────────────────────────────────────────────────

    public function testAThreadWithRepliesResolveAndReopen(): void
    {
        $id = $this->document();

        $made = $this->comment(self::SUGGESTER, $id, 'b1', '30 days', 'Is this right?');
        self::assertSame(201, $made->getStatusCode(), $made->getBody());
        $thread = $this->data($made);
        self::assertSame('b1', $thread['anchor_block_id']);
        self::assertSame('30 days', $thread['quote']);
        self::assertNull($thread['resolved']);
        // assertEquals: jsonb (PostgreSQL) keeps an object's keys in its own order.
        self::assertEquals([['type' => 'text', 'text' => 'Is this right?']], $thread['messages'][0]['body']);
        self::assertSame(['kind' => 'person', 'id' => self::SUGGESTER, 'name' => 'suggester'], $thread['messages'][0]['by']);

        $reply = $this->reply(self::WRITER, $id, (int) $thread['id'], 'Yes.');
        self::assertSame(201, $reply->getStatusCode(), $reply->getBody());
        self::assertSame('writer', $this->data($reply)['by']['name']);

        $resolved = $this->data($this->post('resolve', self::WRITER, $id, ['tid' => (string) $thread['id']]));
        self::assertSame(self::WRITER, $resolved['resolved']['by']['id']);
        self::assertCount(2, $resolved['messages']);
        self::assertSame([], $this->data($this->get('listComments', self::VIEWER, $id, '?resolved=0')));
        self::assertCount(1, $this->data($this->get('listComments', self::VIEWER, $id, '?resolved=1')));

        $reopened = $this->data($this->post('reopen', self::SUGGESTER, $id, ['tid' => (string) $thread['id']]));
        self::assertNull($reopened['resolved']);
        self::assertSame(
            ['documents.comment.resolved', 'documents.comment.reopened'],
            $this->q("SELECT action FROM audit_log WHERE action LIKE 'documents.comment.%' ORDER BY id")->fetchAll(PDO::FETCH_COLUMN),
        );

        self::assertSame(422, $this->comment(self::SUGGESTER, $id, 'nope', 'x', 'y')->getStatusCode(), 'the anchor must be a block of the draft');
        self::assertSame(1, $this->data($this->show(self::WRITER, $id))['draft_version'], 'commenting never writes the draft');
    }

    public function testMentionsNotifyThePeopleWhoCanReadTheDocument(): void
    {
        $id = $this->document();

        $res = $this->comment(self::SUGGESTER, $id, 'b1', 'Pay', 'Please check', [self::WRITER, self::VIEWER, self::SUGGESTER, self::FOREIGN, 999]);
        self::assertSame(201, $res->getStatusCode(), $res->getBody());
        self::assertSame(
            [self::WRITER, self::VIEWER, self::SUGGESTER],
            array_column($this->data($res)['messages'][0]['mentions'], 'id'),
            'only members of the workspace are kept',
        );
        $told = $this->q("SELECT recipient_profile_id FROM notifications WHERE type = 'document.comment.mention' ORDER BY recipient_profile_id")->fetchAll(PDO::FETCH_COLUMN);
        self::assertSame([(string) self::VIEWER, (string) self::WRITER], array_map('strval', $told), 'not the author, not another tenant\'s');
        $subject = $this->q("SELECT subject FROM notifications WHERE type = 'document.comment.mention' LIMIT 1")->fetchColumn();
        self::assertSame('suggester mentioned you in Terms', $subject);

        // A personal document: a mentioned person who can't read it isn't told.
        $personal = $this->document(self::WRITER, 'personal');
        $this->comment(self::WRITER, $personal, 'b1', 'Pay', 'Note to self', [self::SUGGESTER]);
        self::assertSame(2, (int) $this->q("SELECT COUNT(*) FROM notifications WHERE type = 'document.comment.mention'")->fetchColumn());
    }

    public function testMentionableListsOnlyMembersWhoCanReadTheDocument(): void
    {
        $this->q("INSERT INTO profile_emails (profile_id, email, verified, is_primary, created_at)
            VALUES (12, 'Rana.Writer@example.test', true, true, CURRENT_TIMESTAMP),
                   (20, 'rana.foreign@example.test', true, true, CURRENT_TIMESTAMP)");
        $id = $this->document();

        $all = $this->data($this->get('mentionable', self::SUGGESTER, $id, '?q=ER'));
        self::assertSame([self::SUGGESTER, self::WRITER, self::VIEWER, self::OWNER], array_column($all, 'id'), 'every member who can read it, matched case-insensitively, never the other tenant\'s');
        self::assertSame(['kind' => 'person', 'id' => self::WRITER, 'name' => 'writer', 'email' => 'Rana.Writer@example.test'], $all[1]);
        self::assertArrayNotHasKey('email', $all[0], 'no email, no key');

        self::assertSame(
            [self::WRITER],
            array_column($this->data($this->get('mentionable', self::SUGGESTER, $id, '?q=rana')), 'id'),
            'matched on email; the foreign Rana is not a member',
        );

        // A personal document: only its creator can read it, so only they are offered.
        $personal = $this->document(self::WRITER, 'personal');
        self::assertSame([self::WRITER], array_column($this->data($this->get('mentionable', self::WRITER, $personal, '?q=er')), 'id'));
        self::assertSame(404, $this->get('mentionable', self::SUGGESTER, $personal, '?q=er')->getStatusCode(), 'a document the caller can\'t see');

        // The tenant boundary, both ways.
        self::assertSame(404, $this->get('mentionable', self::FOREIGN, $id, '?q=er', self::OTHER_TENANT)->getStatusCode());
        $foreignDoc = $this->document(self::FOREIGN, 'tenant', self::OTHER_TENANT);
        self::assertSame([self::FOREIGN], array_column($this->data($this->get('mentionable', self::FOREIGN, $foreignDoc, '?q=e', self::OTHER_TENANT)), 'id'));

        self::assertSame(403, $this->get('mentionable', self::VIEWER, $id, '?q=er')->getStatusCode(), 'needs documents:comment');
        self::assertSame(422, $this->get('mentionable', self::SUGGESTER, $id, '?q=')->getStatusCode());
        self::assertSame(422, $this->get('mentionable', self::SUGGESTER, $id)->getStatusCode());
    }

    // ── publishing, the draft, changes ───────────────────────────────────

    public function testADraftSaveThatRemovesTheAnchorMarksTheSuggestionStale(): void
    {
        $id = $this->document();
        $gone = (int) $this->data($this->suggest(self::SUGGESTER, $id, $this->rewrite()))['id'];
        $kept = (int) $this->data($this->suggest(self::SUGGESTER, $id, ['kind' => 'block', 'summary' => 'Drop', 'patch' => ['op' => 'delete-blocks', 'blockIds' => ['h1']]]))['id'];
        self::assertSame([['kind' => 'open-suggestions', 'count' => 2]], $this->data($this->publishCheck($id)));

        $saved = $this->save(self::WRITER, $id, '"1"', ['blocks' => [
            ['id' => 'b1', 'type' => 'paragraph', 'content' => [['type' => 'text', 'text' => 'Pay on receipt.']]],
            ['id' => 'h1', 'type' => 'heading', 'level' => 2, 'content' => [['type' => 'text', 'text' => 'Notes']]],
        ]]);
        self::assertSame(200, $saved->getStatusCode(), $saved->getBody());

        $all = $this->data($this->get('listSuggestions', self::WRITER, $id, '?status=all'));
        self::assertSame([[$gone, 'stale'], [$kept, 'open']], array_map(static fn (array $s): array => [$s['id'], $s['status']], $all));
        self::assertSame([['kind' => 'open-suggestions', 'count' => 1]], $this->data($this->publishCheck($id)), 'a stale suggestion no longer blocks publishing');

        $bulk = $this->data($this->decideMany(self::WRITER, $id, ['decision' => 'accepted']));
        self::assertSame(['decided' => [$kept], 'stale' => [], 'draft_version' => 3], $bulk, 'the bulk decision covers open ones only');
        self::assertSame([], $this->data($this->publishCheck($id)));
    }

    public function testThePublishCheckBlocksOnOpenSuggestions(): void
    {
        $id = $this->document();
        self::assertSame([], $this->data($this->publishCheck($id)));

        $sid = (int) $this->data($this->suggest(self::SUGGESTER, $id, $this->rewrite()))['id'];
        $this->suggest(self::SUGGESTER, $id, ['kind' => 'block', 'summary' => 'Drop', 'patch' => ['op' => 'delete-blocks', 'blockIds' => ['h1']]]);
        self::assertSame([['kind' => 'open-suggestions', 'count' => 2]], $this->data($this->publishCheck($id)));

        $this->decideMany(self::OWNER, $id, ['decision' => 'rejected']);
        self::assertSame([], $this->data($this->publishCheck($id)));
        self::assertGreaterThan(0, $sid);
    }

    public function testADraftSaveRefusesSuggestionMarksAndAttrs(): void
    {
        $id = $this->document();

        $marked = $this->save(self::WRITER, $id, '"1"', ['blocks' => [
            ['id' => 'b1', 'type' => 'paragraph', 'content' => [['type' => 'text', 'text' => '14', 'marks' => [['type' => 'insertion', 'suggestionId' => '1']]]]],
        ]]);
        self::assertSame(422, $marked->getStatusCode());
        self::assertSame('suggestion_in_draft', $this->details($marked)['code']);

        $deleted = $this->save(self::WRITER, $id, '"1"', ['blocks' => [
            ['id' => 'b1', 'type' => 'paragraph', 'content' => [['type' => 'text', 'text' => '30', 'marks' => [['type' => 'deletion', 'suggestionId' => '1']]]]],
        ]]);
        self::assertSame(422, $deleted->getStatusCode());

        $attr = $this->save(self::WRITER, $id, '"1"', ['blocks' => [
            ['id' => 'b1', 'type' => 'divider', 'suggestion' => ['id' => '1', 'action' => 'insert']],
        ]]);
        self::assertSame(422, $attr->getStatusCode());
        self::assertSame(1, $this->data($this->show(self::WRITER, $id))['draft_version']);
    }

    public function testChangesSinceACursor(): void
    {
        $id = $this->document();
        $sid = (int) $this->data($this->suggest(self::SUGGESTER, $id, $this->rewrite()))['id'];

        $first = $this->data($this->get('changes', self::VIEWER, $id));
        self::assertSame(1, $first['draft_version']);
        self::assertSame([$sid], array_column($first['suggestions'], 'id'), 'without since, everything');
        self::assertIsString($first['cursor']);

        // Make the earlier rows older than the cursor's overlap.
        $this->pdo->exec("UPDATE document_suggestions SET created_at = '2020-01-01 00:00:00', updated_at = '2020-01-01 00:00:00'");
        $quiet = $this->data($this->get('changes', self::VIEWER, $id, '?since=' . urlencode($first['cursor'])));
        self::assertSame([], $quiet['suggestions']);
        self::assertSame([], $quiet['comments']);

        $tid = (int) $this->data($this->comment(self::SUGGESTER, $id, 'b1', 'Pay', 'Hm'))['id'];
        $this->decide(self::WRITER, $id, $sid, 'accepted');
        $next = $this->data($this->get('changes', self::VIEWER, $id, '?since=' . urlencode($quiet['cursor'])));
        self::assertSame(2, $next['draft_version']);
        self::assertSame([[$sid, 'accepted']], array_map(static fn (array $s): array => [$s['id'], $s['status']], $next['suggestions']));
        self::assertSame([$tid], array_column($next['comments'], 'id'));

        self::assertSame(422, $this->get('changes', self::VIEWER, $id, '?since=yesterday')->getStatusCode());
    }

    // ── helpers ──────────────────────────────────────────────────────────

    /** @return array<string, mixed> */
    private function rewrite(): array
    {
        return ['kind' => 'text', 'summary' => 'Replace 30 days with 14 days', 'patch' => [
            'op' => 'replace-text', 'blockId' => 'b1', 'deleteText' => '30', 'occurrence' => 0,
            'insert' => [['type' => 'text', 'text' => '14']],
        ]];
    }

    private function document(int $owner = self::OWNER, string $scope = 'tenant', int $tenant = self::TENANT): int
    {
        return $this->repo->create($tenant, [
            'kind' => 'template',
            'title' => 'Terms',
            'lang' => 'en',
            'default_view' => 'page',
            'blocks' => [
                ['id' => 'b1', 'type' => 'paragraph', 'content' => [['type' => 'text', 'text' => 'Pay within 30 days.']]],
                ['id' => 'h1', 'type' => 'heading', 'level' => 2, 'content' => [['type' => 'text', 'text' => 'Notes']]],
            ],
            'scope' => $scope,
            'created_by' => $owner,
        ]);
    }

    /** @param array<string, string> $headers */
    private function request(string $method, string $path, int $userId, string $body = '', int $tenant = self::TENANT, array $headers = []): Request
    {
        TenantContext::reset();
        TenantContext::setTenantId($tenant);
        $req = new Request($method, $path, $headers, $body);
        $req->user = (object) ['profile_id' => $userId, 'active_tenant_id' => $tenant];

        return $req;
    }

    /** @param array<string, mixed> $body */
    private function suggest(int $userId, int $id, array $body, int $tenant = self::TENANT): Response
    {
        return $this->handler->createSuggestion($this->request('POST', "/api/document-sources/{$id}/suggestions", $userId, (string) json_encode($body), $tenant), ['id' => (string) $id]);
    }

    private function decide(int $userId, int $id, int $sid, string $decision, int $tenant = self::TENANT): Response
    {
        return $this->handler->decide(
            $this->request('POST', "/api/document-sources/{$id}/suggestions/{$sid}/decision", $userId, (string) json_encode(['decision' => $decision]), $tenant),
            ['id' => (string) $id, 'sid' => (string) $sid],
        );
    }

    /** @param array<string, mixed> $body */
    private function decideMany(int $userId, int $id, array $body): Response
    {
        return $this->handler->decideMany($this->request('POST', "/api/document-sources/{$id}/suggestions/decisions", $userId, (string) json_encode($body)), ['id' => (string) $id]);
    }

    /** @param list<int> $mentions */
    private function comment(int $userId, int $id, string $anchor, string $quote, string $text, array $mentions = [], int $tenant = self::TENANT): Response
    {
        $body = ['anchor_block_id' => $anchor, 'quote' => $quote, 'body' => [['type' => 'text', 'text' => $text]], 'mentions' => $mentions];

        return $this->handler->createThread($this->request('POST', "/api/document-sources/{$id}/comments", $userId, (string) json_encode($body), $tenant), ['id' => (string) $id]);
    }

    private function reply(int $userId, int $id, int $tid, string $text, int $tenant = self::TENANT): Response
    {
        return $this->handler->addMessage(
            $this->request('POST', "/api/document-sources/{$id}/comments/{$tid}/messages", $userId, (string) json_encode(['body' => [['type' => 'text', 'text' => $text]]]), $tenant),
            ['id' => (string) $id, 'tid' => (string) $tid],
        );
    }

    /** @param array<string, string> $params */
    private function post(string $method, int $userId, int $id, array $params): Response
    {
        return $this->handler->{$method}($this->request('POST', "/api/document-sources/{$id}/x", $userId, '{}'), ['id' => (string) $id] + $params);
    }

    private function get(string $method, int $userId, int $id, string $query = '', int $tenant = self::TENANT): Response
    {
        return $this->handler->{$method}($this->request('GET', "/api/document-sources/{$id}/x" . $query, $userId, '', $tenant), ['id' => (string) $id]);
    }

    private function show(int $userId, int $id): Response
    {
        return $this->sources->show($this->request('GET', '/api/document-sources/' . $id, $userId), ['id' => (string) $id]);
    }

    /** @param array<string, mixed> $body */
    private function save(int $userId, int $id, string $ifMatch, array $body): Response
    {
        return $this->sources->saveDraft(
            $this->request('PUT', "/api/document-sources/{$id}/draft", $userId, (string) json_encode($body), self::TENANT, ['If-Match' => $ifMatch]),
            ['id' => (string) $id],
        );
    }

    private function publishCheck(int $id): Response
    {
        return $this->publishing->publishCheck($this->request('GET', "/api/document-sources/{$id}/publish-check", self::OWNER), ['id' => (string) $id]);
    }

    /** @return array<mixed> */
    private function data(Response $res): array
    {
        $decoded = json_decode($res->getBody(), true);
        self::assertIsArray($decoded, $res->getBody());
        self::assertArrayHasKey('data', $decoded, $res->getBody());

        return $decoded['data'];
    }

    private function q(string $sql): \PDOStatement
    {
        $stmt = $this->pdo->query($sql);
        self::assertNotFalse($stmt, $sql);

        return $stmt;
    }

    private static function header(Response $res, string $name): ?string
    {
        foreach ($res->getHeaders() as $key => $value) {
            if (strcasecmp($key, $name) === 0) {
                return $value;
            }
        }

        return null;
    }

    /** @return array<string, mixed> */
    private function details(Response $res): array
    {
        $decoded = json_decode($res->getBody(), true);

        return is_array($decoded['details'] ?? null) ? $decoded['details'] : [];
    }

    private function makeSchema(): PDO
    {
        $pdo = SchemaFromMigrations::make(true);
        $pdo->exec("INSERT INTO tenants (id, name, slug) VALUES (1, 'a', 'a'), (2, 'b', 'b')");
        $pdo->exec("INSERT OR IGNORE INTO roles (id, name, description, tenant_id, created_at) VALUES (1, 'admin', '', NULL, datetime('now'))");
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
                $this->grant($pdo, $role, $permission);
            }
        }
        $pdo->exec("
            INSERT INTO profiles (id, display_name, password_hash, two_factor_enabled, two_factor_backup_codes_version, token_epoch, created_at, updated_at) VALUES
                (10, 'owner',     'x', false, 0, 0, CURRENT_TIMESTAMP, CURRENT_TIMESTAMP),
                (11, 'viewer',    'x', false, 0, 0, CURRENT_TIMESTAMP, CURRENT_TIMESTAMP),
                (12, 'writer',    'x', false, 0, 0, CURRENT_TIMESTAMP, CURRENT_TIMESTAMP),
                (13, 'suggester', 'x', false, 0, 0, CURRENT_TIMESTAMP, CURRENT_TIMESTAMP),
                (20, 'foreign',   'x', false, 0, 0, CURRENT_TIMESTAMP, CURRENT_TIMESTAMP)
        ");
        $pdo->exec("
            INSERT INTO memberships (id, profile_id, tenant_id, role_id, status, created_at) VALUES
                (1000, 10, 1, 1,   'active', datetime('now')),
                (1001, 11, 1, 101, 'active', datetime('now')),
                (1002, 12, 1, 102, 'active', datetime('now')),
                (1003, 13, 1, 103, 'active', datetime('now')),
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
