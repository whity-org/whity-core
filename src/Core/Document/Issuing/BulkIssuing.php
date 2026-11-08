<?php

declare(strict_types=1);

namespace Whity\Core\Document\Issuing;

use PDO;
use Psr\Log\LoggerInterface;
use Whity\Auth\RoleChecker;
use Whity\Core\Audit\Actor;
use Whity\Core\Audit\AuditContext;
use Whity\Core\Queue\JobRepository;
use Whity\Core\RBAC\CorePermissions;
use Whity\Core\RBAC\ScopedPermissionSet;
use Whity\Sdk\Documents\BulkIssueItemResult;
use Whity\Sdk\Documents\BulkIssueRequest;
use Whity\Sdk\Documents\BulkIssueStatus;

/**
 * Bulk issuing from one template (SDK 1.83, WHIT-997): `Documents::issueMany()`.
 *
 * SUBMITTING writes the batch and its items and queues {@see IssueBatchJob};
 * nothing is issued in the caller's request. A batch belongs to its workspace
 * and its OWNER: `plugin:<name>` for a plugin's background job, `person:<id>`
 * in a request (the host can't tell which plugin a request is for). The batch
 * key is unique per workspace and owner: the same batch sent again answers
 * with the batch it already started (`resubmitted`), a different batch under a
 * key already used is refused (`batch_key_conflict`), and any other owner or
 * workspace never sees the batch.
 *
 * RUNNING ({@see run()}) issues pending items in order, through the one
 * issuing service, as the owner:
 *  - a person, checked AS EACH ITEM RUNS: still an active member of the
 *    workspace and still holding `documents:issue` (as `POST /api/documents`
 *    needs), with the permissions they hold then. Taking either away mid-batch
 *    refuses the rest;
 *  - a plugin in the background, with the permissions it declared, only from
 *    its own slots and only while the workspace allows it.
 * Each item is preflighted first (template, record, values, origin key,
 * render, and `document.issuing` listeners with `check_only`), and a refusal
 * is recorded with its reason without writing anything. Then it is issued,
 * which checks everything again.
 *
 * PACING. The render tier is one browser with a shared rate limit
 * (`RENDER_RATE_LIMIT_MAX` renders a minute, docs/wiki/Document-Render-Service.md).
 * A batch that renders issues at most `$rendersPerMinute` items a run, a
 * minute apart, by default half the limit, so people rendering interactively
 * keep the other half. Its jobs run on a low priority. When the render tier
 * refuses (a 429, or it is down), the run stops and waits 1, 2, 4, 8, then 16
 * minutes before the next item; a document already raised without its file
 * keeps its id and can be rendered later.
 *
 * Every item carries an origin key (its own, or one from the owner, batch key
 * and position), so a job that dies between issuing an item and recording it
 * replays that item's document when it resumes, instead of issuing a second.
 *
 * CANCELLING ({@see cancel()}) marks the pending items `cancelled`; issued
 * documents stay.
 */
final class BulkIssuing
{
    /** Items a run takes when nothing renders. */
    public const CHUNK = 25;
    /** Jobs run lowest number first; interactive work is 0. */
    public const PRIORITY = 100;
    private const PACE_SECONDS = 60;
    private const MAX_BACKOFF_STEPS = 4;

    private readonly int $rendersPerMinute;

    /** @param \Closure(): TemplateIssuing $issuing */
    public function __construct(
        private readonly PDO $pdo,
        private readonly RoleChecker $roleChecker,
        private readonly \Closure $issuing,
        private readonly ?JobRepository $jobs = null,
        private readonly ?LoggerInterface $logger = null,
        ?int $rendersPerMinute = null,
    ) {
        $this->rendersPerMinute = max(1, $rendersPerMinute ?? 15);
    }

    /**
     * Half the render service's limit, from the environment the render
     * service reads (`RENDER_RATE_LIMIT_MAX`, default 30 a minute).
     *
     * @param array<string, mixed> $env
     */
    public static function rendersPerMinuteFrom(array $env): int
    {
        $limit = (int) ($env['RENDER_RATE_LIMIT_MAX'] ?? getenv('RENDER_RATE_LIMIT_MAX') ?: 30);

        return max(1, intdiv(max(1, $limit), 2));
    }

    /**
     * @throws DocumentIssueRefused `batch_key_conflict` (409), or `forbidden` (403) when the person may not issue
     */
    public function submit(ActingAs $actor, BulkIssueRequest $request): BulkIssueStatus
    {
        if ($actor->plugin === null && !$this->personMayIssue($actor)) {
            throw new DocumentIssueRefused('forbidden', 'Issuing documents needs documents:issue', 403);
        }
        if (count($request->items) > BulkIssueRequest::MAX_ITEMS) {
            throw DocumentIssueRefused::invalid('A batch has at most ' . BulkIssueRequest::MAX_ITEMS . ' items');
        }
        $owner = self::owner($actor);
        $hash = self::hash($request);

        $existing = $this->findByKey($actor->tenantId, $owner, $request->batchKey);
        if ($existing !== null) {
            return $this->resent($actor, $existing, $hash);
        }

        $own = !$this->pdo->inTransaction();
        if ($own) {
            $this->pdo->beginTransaction();
        }
        try {
            $stmt = $this->pdo->prepare(
                "INSERT INTO document_issue_batches
                    (tenant_id, owner, batch_key, request_hash, actor_profile_id, actor_plugin, actor_permissions, template_slot,
                     template_source_id, render, status, total, created_at, updated_at)
                 VALUES (:tenant_id, :owner, :batch_key, :request_hash, :profile, :plugin, :permissions, :slot,
                     :source_id, :render, 'queued', :total, CURRENT_TIMESTAMP, CURRENT_TIMESTAMP)"
            );
            $stmt->execute([
                ':tenant_id'    => $actor->tenantId,
                ':owner'        => $owner,
                ':batch_key'    => $request->batchKey,
                ':request_hash' => $hash,
                ':profile'      => $actor->plugin === null ? $actor->profileId : null,
                ':plugin'       => $actor->plugin,
                ':permissions'  => $actor->plugin !== null ? json_encode($actor->declared, JSON_THROW_ON_ERROR) : null,
                ':slot'         => $request->template->slot,
                ':source_id'    => $request->template->sourceId,
                ':render'       => match ($request->render) { true => 'yes', false => 'no', null => 'auto' },
                ':total'        => count($request->items),
            ]);
            $batchId = (int) $this->pdo->lastInsertId();

            $insert = $this->pdo->prepare(
                'INSERT INTO document_issue_batch_items (tenant_id, batch_id, position, record, field_values, title, origin_key, status, updated_at)
                 VALUES (:tenant_id, :batch_id, :position, :record, :values, :title, :origin_key, \'pending\', CURRENT_TIMESTAMP)'
            );
            foreach ($request->items as $i => $item) {
                $insert->execute([
                    ':tenant_id'  => $actor->tenantId,
                    ':batch_id'   => $batchId,
                    ':position'   => $i,
                    ':record'     => $item->record,
                    ':values'     => $item->values === [] ? null : json_encode($item->values, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE),
                    ':title'      => $item->title !== null ? mb_substr($item->title, 0, 255) : null,
                    ':origin_key' => $item->originKey ?? self::originKey($owner, $request->batchKey, $i),
                ]);
            }
            if ($own) {
                $this->pdo->commit();
            }
        } catch (\PDOException $e) {
            if ($own && $this->pdo->inTransaction()) {
                $this->pdo->rollBack();
            }
            // Sent twice at once: the unique key let one through.
            $existing = $this->findByKey($actor->tenantId, $owner, $request->batchKey);
            if ($existing === null) {
                throw $e;
            }

            return $this->resent($actor, $existing, $hash);
        }

        $this->enqueue($actor->tenantId, $batchId);

        return $this->statusOf($actor->tenantId, $batchId) ?? throw new \RuntimeException('The batch was not recorded');
    }

    /** The batch, when it is this caller's in this workspace; null otherwise. */
    public function status(ActingAs $actor, int $batchId): ?BulkIssueStatus
    {
        return $this->mine($actor, $batchId) === null ? null : $this->statusOf($actor->tenantId, $batchId);
    }

    /** Cancel this caller's batch: pending items stop, issued ones stay. */
    public function cancel(ActingAs $actor, int $batchId): ?BulkIssueStatus
    {
        $batch = $this->mine($actor, $batchId);
        if ($batch === null) {
            return null;
        }
        if (in_array($batch['status'], [BulkIssueStatus::QUEUED, BulkIssueStatus::RUNNING], true)) {
            $own = !$this->pdo->inTransaction();
            if ($own) {
                $this->pdo->beginTransaction();
            }
            $items = $this->pdo->prepare(
                "UPDATE document_issue_batch_items SET status = 'cancelled', updated_at = CURRENT_TIMESTAMP
                  WHERE tenant_id = :tenant_id AND batch_id = :batch_id AND status = 'pending'"
            );
            $items->execute([':tenant_id' => $actor->tenantId, ':batch_id' => $batchId]);
            $this->pdo->prepare(
                "UPDATE document_issue_batches
                    SET status = 'cancelled', cancelled = cancelled + :n, finished_at = CURRENT_TIMESTAMP, updated_at = CURRENT_TIMESTAMP
                  WHERE id = :id AND tenant_id = :tenant_id"
            )->execute([':n' => $items->rowCount(), ':id' => $batchId, ':tenant_id' => $actor->tenantId]);
            if ($own) {
                $this->pdo->commit();
            }
        }

        return $this->statusOf($actor->tenantId, $batchId);
    }

    /**
     * Issue the next items of a batch. Called by {@see IssueBatchJob} in the
     * batch's workspace; `delay` is how long to wait before the next run.
     *
     * @return array{done: bool, remaining: int, delay: int}
     */
    public function run(int $tenantId, int $batchId, ?int $chunk = null): array
    {
        $batch = $this->find($tenantId, $batchId);
        if ($batch === null || !in_array($batch['status'], [BulkIssueStatus::QUEUED, BulkIssueStatus::RUNNING], true)) {
            return ['done' => true, 'remaining' => 0, 'delay' => 0];
        }
        $this->pdo->prepare(
            "UPDATE document_issue_batches SET status = 'running', updated_at = CURRENT_TIMESTAMP
              WHERE id = :id AND tenant_id = :tenant_id AND status = 'queued'"
        )->execute([':id' => $batchId, ':tenant_id' => $tenantId]);

        $renders = $batch['render'] !== 'no';
        $chunk ??= $renders ? min(self::CHUNK, $this->rendersPerMinute) : self::CHUNK;
        $actor = $this->actorOf($tenantId, $batch);
        $previous = AuditContext::getActor();
        $previousIp = AuditContext::getIpAddress();
        AuditContext::setActor($actor->plugin === null ? Actor::person($actor->profileId) : Actor::system(), $previousIp);
        $backoff = false;
        try {
            $stmt = $this->pdo->prepare(
                "SELECT id, position, record, field_values, title, origin_key FROM document_issue_batch_items
                  WHERE tenant_id = :tenant_id AND batch_id = :batch_id AND status = 'pending'
                  ORDER BY position LIMIT " . max(1, $chunk)
            );
            $stmt->execute([':tenant_id' => $tenantId, ':batch_id' => $batchId]);
            foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $item) {
                if ($this->stillRunning($tenantId, $batchId) === false) {
                    break; // cancelled under us
                }
                if ($this->runItem($tenantId, $batchId, $batch, $actor, $item) === 'backoff') {
                    $backoff = true;
                    break;
                }
            }
        } finally {
            AuditContext::setActor($previous, $previousIp);
        }

        $steps = $this->backoffs($tenantId, $batchId, $backoff);
        $remaining = $this->pendingCount($tenantId, $batchId);
        if ($remaining === 0 && $this->stillRunning($tenantId, $batchId)) {
            $this->pdo->prepare(
                "UPDATE document_issue_batches SET status = 'done', finished_at = CURRENT_TIMESTAMP, updated_at = CURRENT_TIMESTAMP
                  WHERE id = :id AND tenant_id = :tenant_id"
            )->execute([':id' => $batchId, ':tenant_id' => $tenantId]);
        }
        $delay = match (true) {
            $remaining === 0 => 0,
            $backoff => self::PACE_SECONDS * (2 ** min($steps - 1, self::MAX_BACKOFF_STEPS)),
            $renders => self::PACE_SECONDS,
            default => 0,
        };

        return ['done' => $remaining === 0, 'remaining' => $remaining, 'delay' => $delay];
    }

    /** Queue the next run of a batch, on a low priority, after `$delay` seconds. */
    public function enqueue(int $tenantId, int $batchId, int $delay = 0): void
    {
        $this->jobs?->enqueue($tenantId, IssueBatchJob::NAME, ['batch_id' => $batchId], [
            'max_attempts' => 5,
            'priority' => self::PRIORITY,
            'delay' => $delay,
        ]);
    }

    /** `plugin:<name>` for a plugin in the background, `person:<id>` otherwise. */
    public static function owner(ActingAs $actor): string
    {
        return $actor->plugin !== null ? 'plugin:' . $actor->plugin : 'person:' . $actor->profileId;
    }

    /** An item's origin key when it brings none: unique per owner, batch key and position. */
    public static function originKey(string $owner, string $batchKey, int $index): string
    {
        return 'batch:' . substr(hash('sha256', $owner), 0, 8) . ':' . $batchKey . ':' . $index;
    }

    /**
     * @param array<string, mixed> $batch
     * @param array<string, mixed> $item
     * @return 'done'|'backoff'
     */
    private function runItem(int $tenantId, int $batchId, array $batch, ActingAs $actor, array $item): string
    {
        $values = is_string($item['field_values'] ?? null) ? json_decode($item['field_values'], true) : null;
        $input = new IssueInput(
            slot: is_string($batch['template_slot'] ?? null) ? $batch['template_slot'] : null,
            sourceId: $batch['template_source_id'] !== null ? (int) $batch['template_source_id'] : null,
            record: $item['record'],
            values: is_array($values) && $values !== [] ? $values : null,
            title: $item['title'],
            originKey: (string) $item['origin_key'],
            render: match ((string) $batch['render']) { 'yes' => true, 'no' => false, default => null },
        );

        $outcome = 'done';
        try {
            if ($actor->plugin === null && !$this->personMayIssue($actor)) {
                throw new DocumentIssueRefused('forbidden', 'The sender is no longer a member who may issue documents here', 403);
            }
            $issuing = ($this->issuing)();
            $preflight = $issuing->preflight($actor, $input);
            if ($preflight['ok'] !== true) {
                $failed = array_values(array_filter($preflight['checks'], static fn (array $c): bool => ($c['ok'] ?? false) !== true))[0] ?? [];
                if (($failed['code'] ?? null) === 'render' && in_array($failed['reason'] ?? null, ['render_unavailable', 'unavailable'], true)) {
                    // The render tier is busy or down: wait, rather than refuse the item.
                    return 'backoff';
                }
                $result = [
                    'status'      => BulkIssueItemResult::REFUSED,
                    'document_id' => null,
                    'reason'      => is_string($failed['reason'] ?? null) ? $failed['reason'] : 'refused',
                    'message'     => is_string($failed['detail'] ?? null) ? $failed['detail'] : (is_string($failed['why'] ?? null) ? $failed['why'] : 'A check refused it'),
                ];
            } else {
                $issued = $issuing->issue($actor, $input);
                $result = [
                    'status'      => $issued['replayed'] ? BulkIssueItemResult::REPLAYED : BulkIssueItemResult::ISSUED,
                    'document_id' => (int) $issued['document']['id'],
                    'reason'      => null,
                    'message'     => null,
                ];
                if (($issued['render']['reason'] ?? null) === 'unavailable') {
                    // Raised without its file: keep the document, slow down.
                    $result['message'] = 'Issued without its PDF: the render service was busy';
                    $outcome = 'backoff';
                }
            }
        } catch (DocumentIssueRefused $e) {
            if ($e->reason === 'render_unavailable') {
                return 'backoff';
            }
            $result = ['status' => BulkIssueItemResult::REFUSED, 'document_id' => null, 'reason' => $e->reason, 'message' => $e->clientMessage];
        } catch (\Throwable $e) {
            $this->logger?->error('A bulk issue item failed', [
                'event'     => 'documents.bulk_item_failed',
                'tenant_id' => $tenantId,
                'batch_id'  => $batchId,
                'position'  => (int) $item['position'],
                'error'     => $e->getMessage(),
            ]);
            $result = ['status' => BulkIssueItemResult::FAILED, 'document_id' => null, 'reason' => null, 'message' => 'The document could not be issued'];
        }

        $own = !$this->pdo->inTransaction();
        if ($own) {
            $this->pdo->beginTransaction();
        }
        $update = $this->pdo->prepare(
            "UPDATE document_issue_batch_items
                SET status = :status, document_id = :document_id, reason = :reason, message = :message, updated_at = CURRENT_TIMESTAMP
              WHERE id = :id AND tenant_id = :tenant_id AND status = 'pending'"
        );
        $update->execute([
            ':status'      => $result['status'],
            ':document_id' => $result['document_id'],
            ':reason'      => $result['reason'] !== null ? mb_substr($result['reason'], 0, 64) : null,
            ':message'     => $result['message'] !== null ? mb_substr($result['message'], 0, 500) : null,
            ':id'          => (int) $item['id'],
            ':tenant_id'   => $tenantId,
        ]);
        if ($update->rowCount() === 1) {
            $column = $result['status'];
            $this->pdo->prepare(
                "UPDATE document_issue_batches SET {$column} = {$column} + 1, updated_at = CURRENT_TIMESTAMP
                  WHERE id = :id AND tenant_id = :tenant_id"
            )->execute([':id' => $batchId, ':tenant_id' => $tenantId]);
        }
        if ($own) {
            $this->pdo->commit();
        }

        return $outcome;
    }

    /** An active member of the workspace who holds documents:issue, checked now. */
    private function personMayIssue(ActingAs $actor): bool
    {
        $stmt = $this->pdo->prepare(
            "SELECT 1 FROM memberships WHERE tenant_id = :tenant_id AND profile_id = :profile_id AND status = 'active'"
        );
        $stmt->execute([':tenant_id' => $actor->tenantId, ':profile_id' => $actor->profileId]);

        return $stmt->fetchColumn() !== false && ($actor->hasPermission)(CorePermissions::DOCUMENTS_ISSUE);
    }

    /** Count the render tier's refusals in a row; a run that got through resets it. */
    private function backoffs(int $tenantId, int $batchId, bool $refused): int
    {
        $this->pdo->prepare(
            'UPDATE document_issue_batches SET backoffs = ' . ($refused ? 'backoffs + 1' : '0') . ', updated_at = CURRENT_TIMESTAMP
              WHERE id = :id AND tenant_id = :tenant_id'
        )->execute([':id' => $batchId, ':tenant_id' => $tenantId]);

        return (int) ($this->find($tenantId, $batchId)['backoffs'] ?? 0);
    }

    private function stillRunning(int $tenantId, int $batchId): bool
    {
        $status = $this->find($tenantId, $batchId)['status'] ?? null;

        return $status === BulkIssueStatus::QUEUED || $status === BulkIssueStatus::RUNNING;
    }

    /** @param array<string, mixed> $batch */
    private function actorOf(int $tenantId, array $batch): ActingAs
    {
        if ($batch['actor_plugin'] !== null) {
            $permissions = json_decode((string) ($batch['actor_permissions'] ?? '[]'), true);

            return ActingAs::background($tenantId, (string) $batch['actor_plugin'], is_array($permissions) ? array_values(array_filter($permissions, 'is_string')) : []);
        }
        $profileId = (int) $batch['actor_profile_id'];

        return new ActingAs($tenantId, $profileId, ScopedPermissionSet::forProfile($this->roleChecker, $profileId, $tenantId));
    }

    /**
     * The batch, when it belongs to this caller in this workspace.
     *
     * @return array<string, mixed>|null
     */
    private function mine(ActingAs $actor, int $batchId): ?array
    {
        $batch = $this->find($actor->tenantId, $batchId);

        return $batch !== null && $batch['owner'] === self::owner($actor) ? $batch : null;
    }

    /** @param array<string, mixed> $existing */
    private function resent(ActingAs $actor, array $existing, string $hash): BulkIssueStatus
    {
        if ($existing['request_hash'] !== $hash) {
            throw new DocumentIssueRefused('batch_key_conflict', 'This batch key already names a different batch', 409, ['batch_key' => $existing['batch_key']]);
        }
        $status = $this->statusOf($actor->tenantId, (int) $existing['id']) ?? throw new \RuntimeException('The batch is gone');

        return new BulkIssueStatus(
            $status->batchId,
            $status->batchKey,
            $status->status,
            $status->total,
            $status->issued,
            $status->replayed,
            $status->refused,
            $status->failed,
            $status->items,
            true,
            $status->cancelled,
        );
    }

    private function statusOf(int $tenantId, int $batchId): ?BulkIssueStatus
    {
        $batch = $this->find($tenantId, $batchId);
        if ($batch === null) {
            return null;
        }
        $stmt = $this->pdo->prepare(
            'SELECT position, origin_key, status, document_id, reason, message FROM document_issue_batch_items
              WHERE tenant_id = :tenant_id AND batch_id = :batch_id ORDER BY position'
        );
        $stmt->execute([':tenant_id' => $tenantId, ':batch_id' => $batchId]);
        $items = [];
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
            $items[] = new BulkIssueItemResult(
                (int) $row['position'],
                (string) $row['status'],
                (string) $row['origin_key'],
                $row['document_id'] !== null ? (int) $row['document_id'] : null,
                $row['reason'] !== null ? (string) $row['reason'] : null,
                $row['message'] !== null ? (string) $row['message'] : null,
            );
        }

        return new BulkIssueStatus(
            (int) $batch['id'],
            (string) $batch['batch_key'],
            (string) $batch['status'],
            (int) $batch['total'],
            (int) $batch['issued'],
            (int) $batch['replayed'],
            (int) $batch['refused'],
            (int) $batch['failed'],
            $items,
            false,
            (int) $batch['cancelled'],
        );
    }

    /** @return array<string, mixed>|null */
    private function find(int $tenantId, int $batchId): ?array
    {
        $stmt = $this->pdo->prepare('SELECT * FROM document_issue_batches WHERE id = :id AND tenant_id = :tenant_id');
        $stmt->execute([':id' => $batchId, ':tenant_id' => $tenantId]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);

        return $row === false ? null : $row;
    }

    /** @return array<string, mixed>|null */
    private function findByKey(int $tenantId, string $owner, string $batchKey): ?array
    {
        $stmt = $this->pdo->prepare(
            'SELECT * FROM document_issue_batches WHERE tenant_id = :tenant_id AND owner = :owner AND batch_key = :batch_key'
        );
        $stmt->execute([':tenant_id' => $tenantId, ':owner' => $owner, ':batch_key' => $batchKey]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);

        return $row === false ? null : $row;
    }

    private function pendingCount(int $tenantId, int $batchId): int
    {
        $stmt = $this->pdo->prepare(
            "SELECT COUNT(*) FROM document_issue_batch_items WHERE tenant_id = :tenant_id AND batch_id = :batch_id AND status = 'pending'"
        );
        $stmt->execute([':tenant_id' => $tenantId, ':batch_id' => $batchId]);

        return (int) $stmt->fetchColumn();
    }

    private static function hash(BulkIssueRequest $request): string
    {
        $items = array_map(static fn ($i): array => [$i->record, $i->values, $i->title, $i->originKey], $request->items);

        return hash('sha256', (string) json_encode(
            [$request->template->slot, $request->template->sourceId, $request->render, $items],
            JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE,
        ));
    }
}
