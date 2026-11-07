<?php

declare(strict_types=1);

namespace Whity\Core\Document\Routing;

use PDO;
use Throwable;
use Whity\Core\Document\DocumentRepository;
use Whity\Core\Document\DocumentSourceRepository;
use Whity\Core\Document\Fields\DocumentFieldRegistry;
use Whity\Core\Document\Issuing\ActingAs;
use Whity\Core\Document\Issuing\DocumentIssuing;
use Whity\Core\Document\Issuing\IssueRequest;
use Whity\Core\Document\Numbering\DocumentNumbering;
use Whity\Core\Document\Print\PrintLabels;
use Whity\Core\Hooks\HookManager;
use Whity\Core\Queue\QueueService;
use Whity\Sdk\Hooks\Events;

/**
 * Makes a route's final document (WHIT-921, #1725): the work of a `finalise`
 * step, which nobody acts on.
 *
 * When a route reaches its finalise step the engine records it
 * ({@see RouteFinalisationRepository::reach()}), in the same transaction as the
 * act that got there, and calls this after the commit. This then:
 *
 *  1. raises the document for a subject that has none (a form submission),
 *     from the step's template, under an origin key naming the route, so a
 *     retry finds the same document rather than raising a second one; and
 *     records it on the subject;
 *  2. hands out the document's number, when its template numbers documents
 *     and it has none yet;
 *  3. renders the APPROVED version (the subject version current when the route
 *     reached the step) with the template, with the verification QR and a
 *     closing line naming the version and its SHA-256, and stores the PDF on
 *     the document as an immutable artifact.
 *
 * WHEN THE RENDER SERVICE IS DOWN the route is already complete; the step
 * stays "waiting", and a queued job ({@see Jobs\FinaliseRouteJob}) tries again
 * with growing pauses. A failure no retry can mend (the template was deleted,
 * or the render service refuses its content) marks the step failed, with the
 * reason, and stops.
 *
 * It never throws to its caller: the act that reached the step has already
 * been committed and reported, and a PDF problem must not undo it.
 */
final class RouteFinaliser implements RouteFinaliseService
{
    public const DONE = 'done';
    public const WAITING = 'waiting';
    public const FAILED = 'failed';
    /** The route has not reached its finalise step, or something on it is still open. */
    public const NOT_YET = 'not_yet';
    /** Another attempt is making it right now. */
    public const BUSY = 'busy';

    /** Reasons recorded on a waiting or failed step. */
    public const REASON_RENDER_UNAVAILABLE = 'render_unavailable';
    public const REASON_TEMPLATE_MISSING = 'template_missing';
    public const REASON_TEMPLATE_UNPUBLISHED = 'template_unpublished';
    public const REASON_RENDER_REFUSED = 'render_refused';
    public const REASON_WITHDRAWN = 'withdrawn';
    public const REASON_NO_SUBJECT = 'no_subject';
    public const REASON_NOT_APPROVED = 'not_approved';
    public const REASON_ERROR = 'error';

    /** How long the first retry waits (seconds); the queue doubles it after each failure. */
    private const RETRY_DELAY = 60;

    /** How many times the queue tries before it gives up (the step stays waiting). */
    private const RETRY_ATTEMPTS = 20;

    private readonly DocumentNumbering $numbering;

    /** @var DocumentIssuing|\Closure(): DocumentIssuing */
    private DocumentIssuing|\Closure $issuing;

    /** @var \Closure(int): bool */
    private \Closure $renderAvailable;

    /**
     * @param DocumentIssuing|\Closure(): DocumentIssuing $issuing built on first
     *        use when a closure: a worker that never finalises needs no render
     *        service or storage
     * @param \Closure(int): bool $renderAvailable tenant id => whether a PDF can be rendered
     */
    public function __construct(
        PDO $db,
        private readonly RouteRepository $routes,
        private readonly RouteRecipientRepository $recipients,
        private readonly RouteEventRepository $events,
        private readonly RouteFinalisationRepository $finalisations,
        private readonly RouteSubjectRegistry $subjects,
        private readonly RouteSubjectVersionRepository $versions,
        private readonly DocumentSourceRepository $sources,
        private readonly DocumentRepository $documents,
        DocumentIssuing|\Closure $issuing,
        \Closure $renderAvailable,
        private readonly ?DocumentFieldRegistry $fields = null,
        private readonly ?QueueService $queue = null,
        private readonly ?HookManager $hooks = null,
        private readonly PrintLabels $labels = new PrintLabels(),
    ) {
        $this->issuing = $issuing;
        $this->renderAvailable = $renderAvailable;
        $this->numbering = new DocumentNumbering($db);
    }

    public function isAvailable(int $tenantId): bool
    {
        try {
            return ($this->renderAvailable)($tenantId);
        } catch (Throwable $e) {
            error_log('[RouteFinaliser] checking the render service failed: ' . $e->getMessage());

            return false;
        }
    }

    public function finalise(int $tenantId, int $routeId): string
    {
        $row = $this->finalisations->findForRoute($tenantId, $routeId);
        if ($row === null) {
            return self::NOT_YET;
        }
        if ($row['status'] !== RouteFinalisationRepository::WAITING) {
            return $row['status'] === RouteFinalisationRepository::DONE ? self::DONE : self::FAILED;
        }
        // The route is complete only when nothing on it is open: another chain
        // may still be travelling, or the subject was sent back afterwards.
        if ($this->recipients->hasOpenOnRoute($tenantId, $routeId)) {
            return self::NOT_YET;
        }
        if (!$this->finalisations->claim($tenantId, (int) $row['id'])) {
            return self::BUSY;
        }
        // A final document is made from what was approved. A route whose last
        // word was a rejection (another branch ended it) makes none.
        if ($this->events->latestVerdictOnRoute($routeId, $tenantId) === RouteVerdict::REJECTED) {
            $this->finalisations->fail($tenantId, (int) $row['id'], self::REASON_NOT_APPROVED, null);

            return self::FAILED;
        }

        $documentId = null;
        try {
            [$outcome, $reason, $documentId, $artifactId] = $this->attempt($tenantId, $row);
        } catch (Throwable $e) {
            error_log('[RouteFinaliser] making the final document of route ' . $routeId . ' failed: ' . $e->getMessage());
            [$outcome, $reason, $artifactId] = [self::WAITING, self::REASON_ERROR, null];
        }

        if ($outcome === self::DONE && $documentId !== null && $artifactId !== null) {
            $this->finalisations->complete($tenantId, (int) $row['id'], $documentId, $artifactId);

            return self::DONE;
        }
        if ($outcome === self::FAILED) {
            $this->finalisations->fail($tenantId, (int) $row['id'], $reason, $documentId);

            return self::FAILED;
        }

        $this->finalisations->release($tenantId, (int) $row['id'], $reason, $documentId);
        $this->scheduleRetry($tenantId, $routeId);

        return self::WAITING;
    }

    /**
     * One attempt. Writes the document and its PDF; leaves the finalisation row
     * to the caller.
     *
     * @param array<string, mixed> $row
     * @return array{0: string, 1: string, 2: ?int, 3: ?int} outcome, reason, document id, artifact id
     */
    private function attempt(int $tenantId, array $row): array
    {
        $subjectType = (string) $row['subject_type'];
        $subjectId = (int) $row['subject_id'];
        $provider = $this->subjects->get($subjectType);
        if (!$provider instanceof RouteSubjectFinaliseInterface || $provider->title($tenantId, $subjectId) === null) {
            return [self::FAILED, self::REASON_NO_SUBJECT, null, null];
        }
        if ($provider->isWithdrawn($tenantId, $subjectId)) {
            return [self::FAILED, self::REASON_WITHDRAWN, null, null];
        }
        $existingDocumentId = $provider->documentId($tenantId, $subjectId);

        $templateId = $row['template_id'] ?? $provider->finalTemplateId($tenantId, $subjectId);
        $source = is_int($templateId) ? $this->sources->findById($templateId, $tenantId) : null;
        if ($source === null) {
            return [self::FAILED, self::REASON_TEMPLATE_MISSING, $existingDocumentId, null];
        }
        $issuing = $this->issuing();
        $templateVersion = $issuing->published($tenantId, $source);
        if ($templateVersion === null) {
            return [self::FAILED, self::REASON_TEMPLATE_UNPUBLISHED, $existingDocumentId, null];
        }

        if (!$this->isAvailable($tenantId)) {
            // Checked before anything is raised, so a waiting submission does not
            // gain a document with no PDF on it.
            return [self::WAITING, self::REASON_RENDER_UNAVAILABLE, $existingDocumentId, null];
        }

        $approved = $this->approvedVersion($tenantId, $row);
        $values = $this->sharedValues($tenantId)
            + $provider->finalValues($tenantId, $subjectId, $approved, (string) ($source['lang'] ?? 'en'));
        $actor = ActingAs::engine($tenantId);

        if ($existingDocumentId === null) {
            $route = $this->routes->findById((int) $row['route_id'], $tenantId);
            $issued = $issuing->issue(new IssueRequest(
                $source,
                $templateVersion,
                $values,
                (string) ($route['title'] ?? $provider->title($tenantId, $subjectId)),
                render: false,
                record: $provider->finalRecord($tenantId, $subjectId),
                originKey: 'route-finalise:' . (int) $row['route_id'],
            ), $actor);
            $existingDocumentId = (int) $issued['document']['id'];
            $provider->attachDocument($tenantId, $subjectId, $existingDocumentId);
        }

        $number = $this->number($tenantId, $existingDocumentId);
        $document = $this->documents->findById($existingDocumentId, $tenantId);
        if ($document === null) {
            return [self::FAILED, self::REASON_NO_SUBJECT, null, null];
        }

        $footnote = $approved['version'] > 0
            ? $this->labels->madeFrom($approved['version'], $approved['content_hash'])
            : '';
        [$outcome, $artifact] = $issuing->renderFinal($actor, $source, $templateVersion, $values, $document, $footnote);

        if ($artifact !== null) {
            if ($number !== null) {
                $this->announceNumber($tenantId, $existingDocumentId, $number, (int) $row['route_id']);
            }

            return [self::DONE, '', $existingDocumentId, (int) $artifact['id']];
        }

        return match ($outcome['reason']) {
            'rejected' => [self::FAILED, self::REASON_RENDER_REFUSED, $existingDocumentId, null],
            default => [self::WAITING, self::REASON_RENDER_UNAVAILABLE, $existingDocumentId, null],
        };
    }

    /**
     * The version the route approved: the one current when it reached the
     * step. A route issued before versions were recorded has none, and prints
     * no closing line.
     *
     * @param array<string, mixed> $row
     * @return array{version: int, content_hash: string, data: mixed}
     */
    private function approvedVersion(int $tenantId, array $row): array
    {
        $subjectType = (string) $row['subject_type'];
        $subjectId = (int) $row['subject_id'];
        $version = is_int($row['subject_version']) ? $row['subject_version'] : null;
        $found = $version !== null ? $this->versions->find($tenantId, $subjectType, $subjectId, $version) : null;
        if ($found !== null) {
            return $found;
        }
        $snapshot = $this->subjects->get($subjectType)?->snapshot($tenantId, $subjectId);

        return ['version' => 0, 'content_hash' => '', 'data' => $snapshot ?? []];
    }

    /**
     * The fields every template has (Company, Today), as nobody in particular
     * reads them.
     *
     * @return array<string, string|null>
     */
    private function sharedValues(int $tenantId): array
    {
        if ($this->fields === null) {
            return [];
        }
        $resolved = $this->fields->resolve($tenantId, 0, static fn (string $permission): bool => false, null, null);

        return $resolved['values'] ?? [];
    }

    /** The number this call handed out, or null. */
    private function number(int $tenantId, int $documentId): ?string
    {
        if (!$this->numbering->isPending($tenantId, $documentId)) {
            return null;
        }
        $assigned = $this->numbering->assign($tenantId, $documentId);

        return $assigned['minted'] ? $assigned['number'] : null;
    }

    private function announceNumber(int $tenantId, int $documentId, string $number, int $routeId): void
    {
        if ($this->hooks === null) {
            return;
        }
        $payload = [
            'id' => $documentId,
            'document_id' => $documentId,
            'tenant_id' => $tenantId,
            'number' => $number,
            'route_id' => $routeId,
            'actor_profile_id' => null,
            // The final document already prints it, so nothing should reprint it.
            'finalised' => true,
        ];
        try {
            $this->hooks->dispatch(Events::DOCUMENT_NUMBERED, $payload);
        } catch (Throwable $e) {
            error_log('[RouteFinaliser] a listener on ' . Events::DOCUMENT_NUMBERED . ' failed: ' . $e->getMessage());
        }
        try {
            $this->hooks->dispatchAsync(Events::DOCUMENT_NUMBERED . '.async', $payload);
        } catch (Throwable $e) {
            error_log('[RouteFinaliser] recording ' . Events::DOCUMENT_NUMBERED . '.async failed: ' . $e->getMessage());
        }
    }

    private function scheduleRetry(int $tenantId, int $routeId): void
    {
        if ($this->queue === null) {
            return;
        }
        try {
            $this->queue->dispatch(Jobs\FinaliseRouteJob::NAME, ['route_id' => $routeId], [
                'tenant_id' => $tenantId,
                'delay' => self::RETRY_DELAY,
                'max_attempts' => self::RETRY_ATTEMPTS,
                // One live retry per route; a second request to retry joins it.
                'idempotency_key' => 'route-finalise:' . $routeId,
            ]);
        } catch (Throwable $e) {
            error_log('[RouteFinaliser] scheduling a retry for route ' . $routeId . ' failed: ' . $e->getMessage());
        }
    }

    private function issuing(): DocumentIssuing
    {
        if ($this->issuing instanceof \Closure) {
            $this->issuing = ($this->issuing)();
        }

        return $this->issuing;
    }
}
