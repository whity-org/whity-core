<?php

declare(strict_types=1);

namespace Whity\Api;

use PDO;
use Whity\Auth\RoleChecker;
use Whity\Core\Db\DbBool;
use Whity\Core\Document\DocumentRepository;
use Whity\Core\Document\DocumentVisibilityPolicy;
use Whity\Core\Document\Routing\DocumentRouter;
use Whity\Core\Document\Routing\DocumentRouteSubject;
use Whity\Core\Document\Routing\RouteAction;
use Whity\Core\Document\Routing\RouteEdgeRepository;
use Whity\Core\Document\Routing\RouteEventRepository;
use Whity\Core\Document\Routing\RouteFinalDocumentFile;
use Whity\Core\Document\Routing\RouteFinalisationRepository;
use Whity\Core\Document\Routing\RouteFinaliseService;
use Whity\Core\Document\Routing\RouteRecipientRepository;
use Whity\Core\Document\Routing\RouteRepository;
use Whity\Core\Document\Routing\RouteStepRepository;
use Whity\Core\Document\Routing\RouteSubjectChanges;
use Whity\Core\Document\Routing\RouteSubjectRegistry;
use Whity\Core\Document\Routing\RouteSubjectVersionRepository;
use Whity\Core\Document\Routing\RoutingPresenter;
use Whity\Core\Document\Routing\RoutingRejectedException;
use Whity\Core\Form\FormSubmissionRouteSubject;
use Whity\Core\RBAC\CorePermissions;
use Whity\Core\RBAC\ScopedPermissionSet;
use Whity\Core\Request;
use Whity\Core\Response;
use Whity\Core\Tenant\TenantContext;

/**
 * Routes read and acted on by their SUBJECT (WHIT-920, #1724; scope moved here
 * from #1722).
 *
 *   GET  /api/v1/routes?subject_type=…&subject_id=…   the subject, its routes,
 *                                                      their trails and items
 *   POST /api/v1/routes/{routeId}/actions              act on a route
 *   GET  /api/v1/routes/{routeId}/files/{fileId}       a file on the subject
 *   GET  /api/v1/routes/{routeId}/final-document       the PDF its finalise step made (WHIT-921)
 *   POST /api/v1/routes/{routeId}/final-document/retry another try at it
 *
 * A route on a form submission has no document, so the document routes
 * (`/api/v1/documents/{id}/routes/…`) cannot reach it. These do, for any
 * subject; the document routes stay as they are.
 *
 * WHO MAY READ. Session-gated with no permission, like the document routes: being
 * reached by the route IS the authorization (migration 113). So a caller reads a
 * subject when they have held a step item on any of its routes, or:
 *  - a document: they may see the document ({@see DocumentVisibilityPolicy});
 *  - a form submission: they hold `forms:read`, which reads every submission.
 * The author's item (a send-back) does not count: the person who sent a form
 * reads it through My submissions, which shows stages and not reviewers (A9).
 * Everybody else gets the same 404 as for a subject that does not exist.
 *
 * Acting needs nothing more than reading: the engine refuses anybody without an
 * open item on the route.
 */
final class RoutesApiHandler
{
    public function __construct(
        private readonly PDO $db,
        private readonly RouteRepository $routes,
        private readonly RouteStepRepository $steps,
        private readonly RouteEventRepository $events,
        private readonly RouteRecipientRepository $recipients,
        private readonly RouteEdgeRepository $edges,
        private readonly DocumentRouter $router,
        private readonly RouteSubjectRegistry $subjects,
        private readonly RouteSubjectVersionRepository $versions,
        private readonly DocumentRepository $documents,
        private readonly DocumentVisibilityPolicy $visibility,
        private readonly RoleChecker $roleChecker,
        private readonly ?FormSubmissionsApiHandler $submissions = null,
        // WHIT-921: what each route's finalise step did, and its PDF.
        private readonly ?RouteFinalisationRepository $finalisations = null,
        private readonly ?RouteFinalDocumentFile $finalFile = null,
        private readonly ?RouteFinaliseService $finaliser = null,
    ) {
    }

    /**
     * GET /api/v1/routes?subject_type=…&subject_id=… — a subject and its routes.
     */
    public function bySubject(Request $request): Response
    {
        $ctx = $this->context($request);
        if ($ctx instanceof Response) {
            return $ctx;
        }
        [$tenantId, $callerId] = $ctx;

        // The query string, from the server or from the path (as the other
        // handlers read it).
        $query = $_GET;
        $qs = parse_url($request->getPath(), PHP_URL_QUERY);
        if (is_string($qs) && $qs !== '') {
            parse_str($qs, $parsed);
            $query = $parsed + $query;
        }
        $type = $query['subject_type'] ?? null;
        $id = $query['subject_id'] ?? null;
        if (!is_string($type) || $type === '' || !is_string($id) || preg_match('/^\d+$/', $id) !== 1) {
            return Response::error("'subject_type' and 'subject_id' are required", 422);
        }
        $subjectId = (int) $id;

        if (!$this->canView($tenantId, $callerId, $type, $subjectId)) {
            return Response::error('Not found', 404);
        }

        $provider = $this->subjects->get($type);
        $defaultQuorum = $this->router->defaultQuorum($tenantId);
        $changes = new RouteSubjectChanges($this->versions, $this->events);
        $people = [];

        $routes = [];
        foreach ($this->routes->listForSubject($type, $subjectId, $tenantId) as $route) {
            $routeId = (int) $route['id'];
            $trail = $this->events->listForRoute($routeId, $tenantId);
            $items = $this->recipients->listForRoute($routeId, $tenantId);
            foreach ($trail as $event) {
                if ($event['actor_profile_id'] !== null) {
                    $people[(int) $event['actor_profile_id']] = true;
                }
            }
            foreach ($items as $item) {
                $people[(int) $item['profile_id']] = true;
            }
            $entered = $this->router->enteredValues($tenantId, $routeId);
            foreach ($entered as $value) {
                if ($value['entered_by'] !== null) {
                    $people[$value['entered_by']] = true;
                }
            }

            $routes[] = RoutingPresenter::route(
                $route,
                $this->steps->listForRoute($routeId, $tenantId),
                $this->edges->listForRoute($routeId, $tenantId),
                $defaultQuorum,
                $this->events->rejectionCountsByStep($routeId, $tenantId),
                $this->recipients->cohortCountsByStep($routeId, $tenantId),
            ) + [
                'trail' => array_map(RoutingPresenter::event(...), $trail),
                'recipients' => array_map(RoutingPresenter::recipient(...), $items),
                // WHIT-1016: what people entered at the steps that ask for values.
                'values' => RoutingPresenter::values($entered),
                // What changed since the version the caller last acted on.
                'changes' => $changes->since($tenantId, $route, $callerId),
                // Whether this route can send it back to anybody: a route with a
                // document sends back to the document's author, one without to
                // the subject's (nobody, for an anonymous submission).
                'can_send_back' => ($route['document_id'] !== null
                    ? $this->subjects->get(DocumentRouteSubject::TYPE)?->author($tenantId, (int) $route['document_id'])
                    : $provider?->author($tenantId, $subjectId)) !== null,
                // WHIT-921: what the finalise step did, the engine's own act.
                'finalisation' => self::withRetry(
                    RoutingPresenter::finalisation($this->finalisations?->findForRoute($tenantId, $routeId)),
                    $this->canManageFinal($tenantId, $callerId, $route),
                ),
            ];
        }

        $latest = $this->versions->latest($tenantId, $type, $subjectId);
        $subject = [
            'type' => $type,
            'id' => $subjectId,
            'title' => $provider?->title($tenantId, $subjectId),
            'document_id' => $provider?->documentId($tenantId, $subjectId),
            'version' => $latest['version'] ?? null,
            'content_hash' => $latest['content_hash'] ?? null,
            // Whether anybody can take it back for changes: false for an
            // anonymous submission, which has nobody to send it to.
            'can_send_back' => $provider?->author($tenantId, $subjectId) !== null,
        ];
        if ($type === FormSubmissionRouteSubject::TYPE && $this->submissions !== null) {
            $subject['form'] = $this->submissions->contentForReviewer($tenantId, $subjectId);
        }

        $names = $this->names($tenantId, array_keys($people));

        return Response::json(['data' => [
            'subject' => $subject,
            'routes' => $routes,
            'people' => $names === [] ? new \stdClass() : $names,
        ]]);
    }

    /**
     * POST /api/v1/routes/{routeId}/actions — act on a route, whatever it
     * travels on. The body is the one the document action takes.
     *
     * @param array<string, string> $params
     */
    public function act(Request $request, array $params): Response
    {
        $resolved = $this->visibleRoute($request, $params);
        if ($resolved instanceof Response) {
            return $resolved;
        }
        [$tenantId, $callerId, $route] = $resolved;

        $parsed = DocumentRoutingApiHandler::parseAct($request);
        if ($parsed instanceof Response) {
            return $parsed;
        }

        // A note FOR the person who sent a form reaches them, so on a route
        // with no document only somebody the route reached may write one.
        // Reading the submission (`forms:read`) is enough for an internal note.
        if ($parsed['action'] === RouteAction::NOTED
            && $parsed['to_author']
            && $route['document_id'] === null
            && !$this->recipients->hasStepItemOnRoute($tenantId, (int) $route['id'], $callerId)) {
            return Response::error(
                'Only the people this was sent to can write a note for the person who sent it. '
                . 'Add an internal note instead.',
                422
            );
        }

        try {
            $outcome = $this->router->act(
                $tenantId,
                $callerId,
                $route,
                $parsed['action'],
                $parsed['note'],
                $parsed['verdict'],
                $parsed['resume_from'],
                $parsed['to_author'],
                $parsed['values'],
            );
        } catch (RoutingRejectedException $e) {
            return Response::error($e->clientMessage, 422);
        }

        return Response::json([
            'data' => RoutingPresenter::event($outcome['event']),
            'resolved' => $outcome['resolved'],
            'delivered' => $outcome['delivered'],
            'decided' => $outcome['decided'],
        ], 201);
    }

    /**
     * GET /api/v1/routes/{routeId}/files/{fileId} — a file attached to the
     * form submission a route travels on, for a reviewer who may read it.
     *
     * @param array<string, string> $params
     */
    public function file(Request $request, array $params): Response
    {
        $resolved = $this->visibleRoute($request, $params);
        if ($resolved instanceof Response) {
            return $resolved;
        }
        [$tenantId, , $route] = $resolved;

        if ($route['subject_type'] !== FormSubmissionRouteSubject::TYPE || $this->submissions === null) {
            return Response::error('File not found', 404);
        }

        return $this->submissions->fileForReviewer($tenantId, (int) $route['subject_id'], (int) ($params['fileId'] ?? 0));
    }

    /**
     * GET /api/v1/routes/{routeId}/final-document — the PDF the route's finalise
     * step made (WHIT-921), for somebody who may read the route. A 404 while it
     * is still waiting.
     *
     * @param array<string, string> $params
     */
    public function finalDocument(Request $request, array $params): Response
    {
        $resolved = $this->visibleRoute($request, $params);
        if ($resolved instanceof Response) {
            return $resolved;
        }
        [$tenantId, $callerId, $route] = $resolved;

        if ($this->finalFile === null) {
            return Response::error('There is no final document yet', 404);
        }
        // The person who sent a form reads its final document only when the
        // form lets them, even if a stage of the route also reached them.
        if (!$this->applicantMayOpen($tenantId, $callerId, $route)) {
            return Response::error('There is no final document yet', 404);
        }

        return $this->finalFile->forRoute($tenantId, (int) $route['id'], (string) $route['title']);
    }

    /**
     * POST /api/v1/routes/{routeId}/final-document/retry — another try at a
     * final document that failed or is still waiting (WHIT-921), for a person
     * who may manage the route: who started it, or who may route documents
     * (a document) or manage forms (a form submission). The queue makes it.
     *
     * @param array<string, string> $params
     */
    public function retryFinalDocument(Request $request, array $params): Response
    {
        $resolved = $this->visibleRoute($request, $params);
        if ($resolved instanceof Response) {
            return $resolved;
        }
        [$tenantId, $callerId, $route] = $resolved;

        if ($this->finaliser === null || $this->finalisations === null) {
            return Response::error('There is no final document to try again', 404);
        }
        if (!$this->canManageFinal($tenantId, $callerId, $route)) {
            return Response::error('Only somebody who may manage this route can try again', 403);
        }
        if (!$this->finaliser->retry($tenantId, (int) $route['id'])) {
            return Response::error('There is no final document to try again', 409);
        }

        return Response::json([
            'data' => RoutingPresenter::finalisation($this->finalisations->findForRoute($tenantId, (int) $route['id'])),
        ], 202);
    }

    /**
     * @param array<string, mixed>|null $finalisation
     * @return array<string, mixed>|null
     */
    private static function withRetry(?array $finalisation, bool $canManage): ?array
    {
        if ($finalisation === null) {
            return null;
        }

        return $finalisation + ['can_retry' => $canManage && $finalisation['status'] !== 'done'];
    }

    /**
     * Who may ask for another try at a route's final document.
     *
     * @param array<string, mixed> $route
     */
    private function canManageFinal(int $tenantId, int $callerId, array $route): bool
    {
        if (($route['created_by'] ?? null) !== null && (int) $route['created_by'] === $callerId) {
            return true;
        }
        $permission = ($route['subject_type'] ?? null) === FormSubmissionRouteSubject::TYPE
            ? CorePermissions::FORMS_MANAGE
            : CorePermissions::DOCUMENTS_ROUTE;

        return $this->roleChecker->hasPermissionForProfile($callerId, $permission, $tenantId);
    }

    /**
     * False only for the person who sent a form whose final document the form
     * keeps from its applicants.
     *
     * @param array<string, mixed> $route
     */
    private function applicantMayOpen(int $tenantId, int $callerId, array $route): bool
    {
        if (($route['subject_type'] ?? null) !== FormSubmissionRouteSubject::TYPE) {
            return true;
        }
        $stmt = $this->db->prepare(
            'SELECT s.submitted_by_profile_id, f.final_document_to_applicant
               FROM form_submissions s
               JOIN forms f ON f.id = s.form_id AND f.tenant_id = s.tenant_id
              WHERE s.id = :id AND s.tenant_id = :tenant_id'
        );
        $stmt->execute([':id' => (int) $route['subject_id'], ':tenant_id' => $tenantId]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!is_array($row) || $row['submitted_by_profile_id'] === null || (int) $row['submitted_by_profile_id'] !== $callerId) {
            return true;
        }

        return DbBool::of($row['final_document_to_applicant'] ?? true);
    }

    /**
     * @param array<string, string> $params
     * @return array{0: int, 1: int, 2: array<string, mixed>}|Response
     */
    private function visibleRoute(Request $request, array $params): array|Response
    {
        $ctx = $this->context($request);
        if ($ctx instanceof Response) {
            return $ctx;
        }
        [$tenantId, $callerId] = $ctx;

        $routeId = (int) ($params['routeId'] ?? 0);
        $route = $routeId > 0 ? $this->routes->findById($routeId, $tenantId) : null;
        if ($route === null
            || !$this->canView($tenantId, $callerId, (string) $route['subject_type'], (int) $route['subject_id'])) {
            return Response::error('Route not found', 404);
        }

        return [$tenantId, $callerId, $route];
    }

    private function canView(int $tenantId, int $callerId, string $type, int $subjectId): bool
    {
        if ($type === DocumentRouteSubject::TYPE) {
            $document = $this->documents->findById($subjectId, $tenantId);

            return $document !== null && $this->visibility->canView(
                $document,
                $callerId,
                ScopedPermissionSet::forProfile($this->roleChecker, $callerId, $tenantId),
            );
        }

        $provider = $this->subjects->get($type);
        if ($provider === null || $provider->title($tenantId, $subjectId) === null) {
            return false;
        }

        if ($this->recipients->hasAnyOnSubject($tenantId, $type, $subjectId, $callerId)) {
            return true;
        }

        return $type === FormSubmissionRouteSubject::TYPE
            && $this->roleChecker->hasPermissionForProfile($callerId, CorePermissions::FORMS_READ, $tenantId);
    }

    /**
     * Display names for the people on these routes, limited to this workspace's
     * members: a reviewer reads who did what, by name, never by id.
     *
     * @param list<int> $ids
     * @return array<string, string>
     */
    private function names(int $tenantId, array $ids): array
    {
        if ($ids === []) {
            return [];
        }

        $placeholders = implode(', ', array_fill(0, count($ids), '?'));
        $stmt = $this->db->prepare(
            'SELECT p.id, p.display_name FROM profiles p
              WHERE p.id IN (' . $placeholders . ')
                AND EXISTS (SELECT 1 FROM memberships m WHERE m.profile_id = p.id AND m.tenant_id = ?)'
        );
        $stmt->execute([...$ids, $tenantId]);

        $names = [];
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
            $names[(string) $row['id']] = trim((string) ($row['display_name'] ?? ''));
        }

        return $names;
    }

    /** @return array{0: int, 1: int}|Response */
    private function context(Request $request): array|Response
    {
        $tenantId = TenantContext::getTenantId();
        if ($tenantId === null) {
            return Response::error('Tenant context is required', 400);
        }
        $actor = $request->user;
        $callerId = is_object($actor) && isset($actor->profile_id) && is_int($actor->profile_id)
            ? $actor->profile_id
            : null;
        if ($callerId === null) {
            return Response::error('Authentication required', 401);
        }

        return [$tenantId, $callerId];
    }
}
