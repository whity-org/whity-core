<?php

declare(strict_types=1);

namespace Whity\Core\Document\Issuing;

use Whity\Auth\RoleChecker;
use Whity\Core\Audit\AuditContext;
use Whity\Core\Document\Slots\TemplateSlotRegistry;
use Whity\Core\RBAC\ScopedPermissionSet;
use Whity\Core\Tenant\TenantContext;

/**
 * Who the SDK's document services act for (WHIT-742, WHIT-996), read from the
 * host's own context, never from the plugin: the person whose request it is,
 * or, in a job of a plugin that declares it issues in the background, that
 * plugin with the permissions it declares. Null when there is neither.
 *
 * {@see SdkDocuments} and {@see SdkTemplates} share it, so reading templates
 * and issuing from them answer for the same caller.
 */
final class SdkActor
{
    /**
     * @param (\Closure(string): (array{plugin: string, permissions: list<string>}|null))|null $jobPlugin
     */
    public function __construct(
        private readonly RoleChecker $roleChecker,
        private readonly ?\Closure $jobPlugin = null,
        private readonly ?TemplateSlotRegistry $slots = null,
    ) {
    }

    public function current(): ?ActingAs
    {
        $tenantId = TenantContext::getTenantId();
        if ($tenantId === null || $tenantId === 0) {
            return null;
        }
        $profileId = AuditContext::getActorUserId();
        if ($profileId !== null) {
            return new ActingAs($tenantId, $profileId, ScopedPermissionSet::forProfile($this->roleChecker, $profileId, $tenantId));
        }

        // No person: a job of a plugin that declares it issues in the background.
        $job = AuditContext::getJob();
        $owner = $job !== null && $this->jobPlugin !== null ? ($this->jobPlugin)($job) : null;
        if ($owner === null || $this->slots === null || !$this->slots->declaresBackground($owner['plugin'])) {
            return null;
        }

        return ActingAs::background($tenantId, $owner['plugin'], $owner['permissions']);
    }
}
