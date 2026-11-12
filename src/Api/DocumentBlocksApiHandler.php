<?php

declare(strict_types=1);

namespace Whity\Api;

use Whity\Auth\RoleChecker;
use Whity\Core\Document\Blocks\DocumentBlockRegistry;
use Whity\Core\RBAC\CorePermissions;
use Whity\Core\RBAC\ScopedPermissionSet;
use Whity\Core\Request;
use Whity\Core\Response;
use Whity\Core\Tenant\TenantContext;

/**
 * GET /api/document-blocks (documents:read): the blocks this workspace's
 * plugins add to the document editor (editor M7, E.17–E.20; SDK 1.103).
 *
 * One entry per plugin: its name, whether it carries Whity's Verified mark
 * (from Whity's marks, never from the plugin) and its blocks, each a
 * declaration Whity's renderer draws: fields, a view, an optional print view
 * and the kinds of document it may go in. `off` lists the workspace's plugins
 * that are installed but switched off, so a block of one shows the plugin-off
 * placeholder by name (E.19); `canEnable` says whether the caller may switch
 * workspace plugins on (plugins:enable).
 */
final class DocumentBlocksApiHandler
{
    public function __construct(
        private readonly DocumentBlockRegistry $blocks,
        private readonly RoleChecker $roleChecker,
    ) {
    }

    public function list(Request $request): Response
    {
        $tenantId = TenantContext::getTenantId();
        if ($tenantId === null) {
            return Response::error('Tenant context is required', 400);
        }
        $user = $request->user;
        $callerId = is_object($user) && isset($user->profile_id) && is_int($user->profile_id) ? $user->profile_id : null;
        if ($callerId === null) {
            return Response::error('Authentication required', 401);
        }
        $can = ScopedPermissionSet::forProfile($this->roleChecker, $callerId, $tenantId);
        if (!$can(CorePermissions::DOCUMENTS_READ)) {
            return Response::error('Insufficient permissions', 403, ['required' => CorePermissions::DOCUMENTS_READ]);
        }

        $listed = $this->blocks->forTenant($tenantId);

        return Response::json(['data' => [
            'plugins' => $listed['plugins'],
            'off' => $listed['off'],
            'canEnable' => $can(CorePermissions::PLUGINS_ENABLE),
        ]]);
    }
}
