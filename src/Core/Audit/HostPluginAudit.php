<?php

declare(strict_types=1);

namespace Whity\Core\Audit;

use PDO;
use Whity\Core\Plugin\PluginIdentity;
use Whity\Core\Tenant\TenantContext;
use Whity\Sdk\Audit\AuditTrail;
use Whity\Sdk\Audit\PluginAudit;
use Whity\Sdk\PluginInterface;

/**
 * The host's plugin audit service (WHIT-1032). The plugin comes from the loader's
 * identity and the workspace from {@see TenantContext}, so neither is something a
 * plugin can state.
 */
final class HostPluginAudit implements PluginAudit
{
    /** @var \Closure(): ?int */
    private readonly \Closure $tenant;

    /**
     * @param (\Closure(): ?int)|null $tenant the workspace in scope; the request's or job's by default
     */
    public function __construct(
        private readonly PDO $db,
        private readonly AuditLoggerInterface $audit,
        ?\Closure $tenant = null,
    ) {
        $this->tenant = $tenant ?? static fn (): ?int => TenantContext::getTenantId();
    }

    public function for(PluginInterface $plugin): AuditTrail
    {
        return new HostAuditTrail($this->db, $this->audit, PluginIdentity::slugOf($plugin), $this->tenant);
    }
}
