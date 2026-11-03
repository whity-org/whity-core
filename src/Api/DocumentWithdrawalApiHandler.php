<?php

declare(strict_types=1);

namespace Whity\Api;

use Whity\Auth\RoleChecker;
use Whity\Core\Document\DocumentRepository;
use Whity\Core\Document\DocumentVisibilityPolicy;
use Whity\Core\Document\DocumentWithdrawal;
use Whity\Core\RBAC\ScopedPermissionSet;
use Whity\Core\Request;
use Whity\Core\Response;
use Whity\Core\Tenant\TenantContext;

/**
 * POST /api/documents/{id}/withdraw (S5).
 *
 * The route carries `documents:withdraw`. The row is then gated on
 * {@see DocumentVisibilityPolicy::canView()} with the same 404 every other read
 * gives, so holding the permission never confirms that a document exists.
 */
final class DocumentWithdrawalApiHandler
{
    public function __construct(
        private readonly DocumentRepository $documents,
        private readonly DocumentVisibilityPolicy $visibility,
        private readonly RoleChecker $roleChecker,
        private readonly DocumentWithdrawal $withdrawal,
    ) {
    }

    /**
     * @param array<string, string> $params
     */
    public function withdraw(Request $request, array $params): Response
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

        $id = (int) ($params['id'] ?? 0);
        $document = $this->documents->findById($id, $tenantId);
        if ($document === null
            || !$this->visibility->canView(
                $document,
                $callerId,
                ScopedPermissionSet::forProfile($this->roleChecker, $callerId, $tenantId),
            )) {
            return Response::error('Document not found', 404);
        }

        try {
            $done = $this->withdrawal->withdraw($tenantId, $callerId, $document);
        } catch (\Throwable $e) {
            error_log('[DocumentWithdrawalApiHandler] withdrawing a document failed: ' . $e->getMessage());

            return Response::error('The document could not be withdrawn', 503);
        }

        if ($done === null) {
            return Response::error('This document has already been withdrawn', 409);
        }

        $fresh = $this->documents->findById($id, $tenantId);

        return Response::json([
            'data' => [
                'id' => $id,
                'withdrawn_at' => $fresh['withdrawn_at'] ?? null,
                'withdrawn_by' => $fresh['withdrawn_by'] ?? null,
                'codes_withdrawn' => $done['codes'],
                'items_closed' => $done['items'],
            ],
        ]);
    }
}
