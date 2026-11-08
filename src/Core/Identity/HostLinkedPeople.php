<?php

declare(strict_types=1);

namespace Whity\Core\Identity;

use PDO;
use Whity\Auth\RoleChecker;
use Whity\Core\Container\HostWiredService;
use Whity\Sdk\Http\ServiceCall;
use Whity\Sdk\Identity\LinkedPeople;
use Whity\Sdk\Identity\LinkedWorkspace;

/**
 * {@see LinkedPeople} for plugins: which workspaces a person at the trusted
 * issuer may act in for one permission (SDK 1.84, WHIT-1011).
 *
 * The issuer is the one that signed the service call, never one the plugin
 * names. The subject is looked up through the GLOBAL sign-in link, the same
 * link `/api/v1/oauth/workspaces` uses, so a tenant-scoped provider's link can
 * never stand in for it. After that, only active memberships count, never the
 * system workspace, and the permission is checked per workspace with the
 * ordinary role checker.
 */
final class HostLinkedPeople implements LinkedPeople, HostWiredService
{
    public function __construct(
        private readonly PDO $db,
        private readonly RoleChecker $roles,
    ) {
    }

    public function workspacesFor(ServiceCall $call, string $permission): array
    {
        $subject = (string) $call->onBehalfOf;
        if ($subject === '' || $permission === '') {
            return [];
        }

        $link = (new ExternalIdentityRepository($this->db))->findGlobalByIssuerSubject($call->issuer, $subject);
        if ($link === null) {
            return [];
        }
        $profileId = (int) $link['profile_id'];

        // Across tenants by design: lists the tenants a linked profile belongs to before any tenant is chosen; scoped by the profile the issuer-signed call resolved to, and each row is then permission-checked in its own tenant
        $stmt = $this->db->prepare(
            "SELECT t.id, t.name FROM memberships m JOIN tenants t ON t.id = m.tenant_id
             WHERE m.profile_id = ? AND m.status = 'active' AND m.tenant_id <> 0
             ORDER BY t.id"
        );
        $stmt->execute([$profileId]);

        $workspaces = [];
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
            $tenantId = (int) $row['id'];
            if ($this->roles->hasPermissionForProfile($profileId, $permission, $tenantId)) {
                $workspaces[] = new LinkedWorkspace($tenantId, (string) $row['name']);
            }
        }

        return $workspaces;
    }
}
