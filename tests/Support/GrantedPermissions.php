<?php

declare(strict_types=1);

namespace Tests\Support;

use PDO;
use Whity\Core\Chat\ChatRecordAccess;
use Whity\Core\Document\DocumentRepository;
use Whity\Core\Document\DocumentVisibilityPolicy;
use Whity\Core\Document\Routing\RouteRecipientRepository;
use Whity\Core\RBAC\ResourceRoleAssignmentRepository;
use Whity\Core\RBAC\ResourceTypeRegistry;
use Whity\Sdk\Rbac\PermissionResolver;

/**
 * A permission resolver for tests: each person holds exactly the permissions
 * listed for them, in every workspace. Change `$grants` during a test to take
 * one away.
 */
final class GrantedPermissions implements PermissionResolver
{
    /** @param array<int, list<string>> $grants profile id => permissions */
    public function __construct(public array $grants = [])
    {
    }

    /** A {@see ChatRecordAccess} on the real repositories, asking these grants. */
    public function chatRecordAccess(PDO $pdo): ChatRecordAccess
    {
        $recipients = new RouteRecipientRepository($pdo);

        return new ChatRecordAccess(
            $pdo,
            $this,
            new DocumentRepository($pdo),
            new DocumentVisibilityPolicy($recipients, new ResourceRoleAssignmentRepository($pdo, new ResourceTypeRegistry())),
            $recipients,
        );
    }

    public function hasPermission(int $profileId, int $tenantId, string $permission, ?string $resourceType = null, ?int $resourceId = null): bool
    {
        return in_array($permission, $this->grants[$profileId] ?? [], true);
    }

    public function hasRole(int $profileId, int $tenantId, string $role, ?string $resourceType = null, ?int $resourceId = null): bool
    {
        return false;
    }

    public function effectivePermissions(int $profileId, int $tenantId, ?string $resourceType = null, ?int $resourceId = null): array
    {
        return $this->grants[$profileId] ?? [];
    }
}
