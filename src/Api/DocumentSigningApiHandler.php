<?php

declare(strict_types=1);

namespace Whity\Api;

use Whity\Auth\RoleChecker;
use Whity\Core\Document\DocumentRepository;
use Whity\Core\Document\DocumentVisibilityPolicy;
use Whity\Core\Document\Routing\RouteEventRepository;
use Whity\Core\Document\Routing\RouteRepository;
use Whity\Core\Document\Routing\RoutingPresenter;
use Whity\Core\Document\Routing\RoutingRejectedException;
use Whity\Core\Document\Signing\InstanceSealKeys;
use Whity\Core\Document\Signing\SavedSignatureRepository;
use Whity\Core\Document\Signing\SignatureAppearance;
use Whity\Core\Document\Signing\SigningRefusedException;
use Whity\Core\Document\Signing\SigningService;
use Whity\Core\RateLimit\ClientIp;
use Whity\Core\RBAC\CorePermissions;
use Whity\Core\RBAC\ScopedPermissionSet;
use Whity\Core\Request;
use Whity\Core\Response;
use Whity\Core\Tenant\TenantContext;
use Whity\Http\JsonBody;

/**
 * Electronic signatures on routed documents (WHIT-922, #1726):
 *
 *   GET    /api/documents/{id}/routes/{routeId}/signing        (session)
 *   POST   /api/documents/{id}/routes/{routeId}/signing/code   (session)
 *   POST   /api/documents/{id}/routes/{routeId}/sign           (session)
 *   GET    /api/documents/{id}/audit                           (session)
 *   GET    /api/me/signature                                   (session)
 *   PUT    /api/me/signature                                   (session)
 *   DELETE /api/me/signature                                   (session)
 *   GET    /api/signing-keys                                   (public)
 *
 * Signing is session-gated with no permission, for the reason acting on a
 * route is: being the person the step reached IS the authorization. A
 * document the caller may not see is reported as missing, never as forbidden.
 *
 * Declining to sign is an ordinary act on the route
 * (`POST .../actions` with `declined` and a reason).
 */
final class DocumentSigningApiHandler
{
    public function __construct(
        private readonly DocumentRepository $documents,
        private readonly RouteRepository $routes,
        private readonly RouteEventRepository $events,
        private readonly DocumentVisibilityPolicy $visibility,
        private readonly RoleChecker $roleChecker,
        private readonly SigningService $signing,
        private readonly SavedSignatureRepository $saved,
        private readonly InstanceSealKeys $keys,
    ) {
    }

    /**
     * GET .../signing — what the caller sees before signing.
     *
     * @param array<string, string> $params
     */
    public function context(Request $request, array $params): Response
    {
        $resolved = $this->resolveRoute($request, $params);
        if ($resolved instanceof Response) {
            return $resolved;
        }
        [$tenantId, $callerId, , $route] = $resolved;

        return Response::json(['data' => $this->signing->context($tenantId, $callerId, $route)]);
    }

    /**
     * POST .../signing/code — email the caller a one-time signing code.
     *
     * @param array<string, string> $params
     */
    public function sendCode(Request $request, array $params): Response
    {
        $resolved = $this->resolveRoute($request, $params);
        if ($resolved instanceof Response) {
            return $resolved;
        }
        [$tenantId, $callerId, , $route] = $resolved;

        try {
            $sent = $this->signing->sendCode($tenantId, $callerId, $route);
        } catch (SigningRefusedException $e) {
            return self::refusal($e);
        }

        return Response::json(['data' => $sent], 202);
    }

    /**
     * POST .../sign — sign the caller's open item on a sign step.
     *
     * @param array<string, string> $params
     */
    public function sign(Request $request, array $params): Response
    {
        $resolved = $this->resolveRoute($request, $params);
        if ($resolved instanceof Response) {
            return $resolved;
        }
        [$tenantId, $callerId, , $route] = $resolved;

        $body = JsonBody::parsed($request);
        try {
            $outcome = $this->signing->sign(
                $tenantId,
                $callerId,
                $route,
                [
                    'content_hash' => $body['content_hash'] ?? null,
                    'consent' => $body['consent'] ?? null,
                    'consent_version' => $body['consent_version'] ?? null,
                    'appearance' => $body['appearance'] ?? null,
                    'use_saved_signature' => $body['use_saved_signature'] ?? false,
                    'save_signature' => $body['save_signature'] ?? false,
                    'second_factor' => $body['second_factor'] ?? null,
                ],
                ClientIp::fromRequest($request),
                $request->getHeader('User-Agent'),
            );
        } catch (SigningRefusedException $e) {
            return self::refusal($e);
        } catch (RoutingRejectedException $e) {
            return Response::error($e->clientMessage, 422);
        }

        return Response::json([
            // The signer's own record, so their own details are included.
            'data' => SigningService::present($outcome['signature']),
            'event' => RoutingPresenter::event($outcome['event']),
            'decided' => $outcome['decided'],
            'resolved' => $outcome['resolved'],
            'delivered' => $outcome['delivered'],
        ], 201);
    }

    /**
     * GET /api/documents/{id}/audit — every act and signature on the document,
     * whether each seal checks, and whether the trail's chain is intact.
     *
     * @param array<string, string> $params
     */
    public function audit(Request $request, array $params): Response
    {
        $resolved = $this->resolveDocument($request, $params);
        if ($resolved instanceof Response) {
            return $resolved;
        }
        [$tenantId, $callerId, $document] = $resolved;
        $documentId = (int) $document['id'];

        // Anyone who may see the document sees who signed, in what capacity,
        // when, how they confirmed, and the drawing. Each signer's email, IP
        // address and browser are shown only to holders of `audit:read`.
        $withDetails = $this->roleChecker->hasPermissionForProfile($callerId, CorePermissions::AUDIT_READ, $tenantId);
        $audit = $this->signing->audit($tenantId, $documentId, $withDetails);
        $total = $this->events->countForDocument($documentId, $tenantId);
        $events = $this->events->listForDocument($documentId, $tenantId, max(1, $total), 0);

        return Response::json(['data' => [
            'document_id' => $documentId,
            'chain' => $audit['chain'],
            'events' => array_map(
                static fn (array $event): array => RoutingPresenter::event($event) + [
                    'prev_hash' => $event['prev_hash'] ?? null,
                    'entry_hash' => $event['entry_hash'] ?? null,
                ],
                $events,
            ),
            'signatures' => $audit['signatures'],
            'keys' => $this->keys->published(),
        ]]);
    }

    /** GET /api/me/signature — the caller's saved drawn signature, or null. */
    public function savedSignature(Request $request): Response
    {
        $ctx = $this->callerContext($request);
        if ($ctx instanceof Response) {
            return $ctx;
        }

        return Response::json(['data' => $this->saved->find($ctx[0], $ctx[1])]);
    }

    /** PUT /api/me/signature — save the caller's drawn signature for reuse. */
    public function saveSignature(Request $request): Response
    {
        $ctx = $this->callerContext($request);
        if ($ctx instanceof Response) {
            return $ctx;
        }

        $body = JsonBody::parsed($request);
        try {
            $appearance = SignatureAppearance::normalise($body['appearance'] ?? null);
        } catch (\InvalidArgumentException) {
            return Response::error(
                'Draw your signature to save it.',
                422,
                ['reason' => SigningRefusedException::APPEARANCE_REQUIRED],
            );
        }
        $this->saved->save($ctx[0], $ctx[1], $appearance);

        return Response::json(['data' => $appearance]);
    }

    /** DELETE /api/me/signature — forget the caller's saved signature. */
    public function deleteSignature(Request $request): Response
    {
        $ctx = $this->callerContext($request);
        if ($ctx instanceof Response) {
            return $ctx;
        }
        $this->saved->delete($ctx[0], $ctx[1]);

        return new Response(204, '');
    }

    /** GET /api/signing-keys — the instance's published public keys. Public. */
    public function publishedKeys(Request $request): Response
    {
        return Response::json(['data' => $this->keys->published()]);
    }

    private static function refusal(SigningRefusedException $e): Response
    {
        return Response::error($e->clientMessage, $e->status, ['reason' => $e->reason]);
    }

    /**
     * @param array<string, string> $params
     * @return array{0: int, 1: int, 2: array<string, mixed>, 3: array<string, mixed>}|Response
     */
    private function resolveRoute(Request $request, array $params): array|Response
    {
        $resolved = $this->resolveDocument($request, $params);
        if ($resolved instanceof Response) {
            return $resolved;
        }
        [$tenantId, $callerId, $document] = $resolved;

        $routeId = (int) ($params['routeId'] ?? 0);
        $route = $routeId > 0 ? $this->routes->findById($routeId, $tenantId) : null;
        if ($route === null || (int) $route['document_id'] !== (int) $document['id']) {
            return Response::error('Route not found', 404);
        }

        return [$tenantId, $callerId, $document, $route];
    }

    /**
     * @param array<string, string> $params
     * @return array{0: int, 1: int, 2: array<string, mixed>}|Response
     */
    private function resolveDocument(Request $request, array $params): array|Response
    {
        $ctx = $this->callerContext($request);
        if ($ctx instanceof Response) {
            return $ctx;
        }
        [$tenantId, $callerId] = $ctx;

        $document = $this->documents->findById((int) ($params['id'] ?? 0), $tenantId);
        if ($document === null || !$this->visibility->canView(
            $document,
            $callerId,
            ScopedPermissionSet::forProfile($this->roleChecker, $callerId, $tenantId),
        )) {
            return Response::error('Document not found', 404);
        }

        return [$tenantId, $callerId, $document];
    }

    /** @return array{0: int, 1: int}|Response */
    private function callerContext(Request $request): array|Response
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
