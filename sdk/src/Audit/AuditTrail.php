<?php

declare(strict_types=1);

namespace Whity\Sdk\Audit;

/**
 * One plugin's audit entries in the current workspace (WHIT-1032). Take it from
 * {@see PluginAudit}.
 *
 * The host attributes every entry. The action and target type are stored under
 * the plugin's own namespace, and the actor, IP address and workspace come from
 * the request or job, so no argument here can name another plugin, a core action
 * or someone else as the actor. Without a workspace in scope every method throws
 * {@see \RuntimeException}.
 *
 * `$action` and `$targetType` are BARE names: lowercase, starting with a letter,
 * with letters, digits, underscores or dots after that. Anything else throws
 * {@see \InvalidArgumentException}.
 */
interface AuditTrail
{
    /**
     * @param array<string, mixed> $metadata What happened. Keys that look like
     *        secrets (password, token, code…) are dropped by the host.
     */
    public function record(string $action, ?string $targetType = null, ?int $targetId = null, array $metadata = []): void;

    /**
     * This plugin's entries in this workspace, newest first, including its
     * declared audited events.
     *
     * @param int         $limit    At most 200.
     * @param int|null    $beforeId Continue after the last entry of the previous page.
     * @param string|null $action   Only this bare action.
     * @return list<AuditEntry>
     */
    public function entries(int $limit = 50, ?int $beforeId = null, ?string $action = null): array;
}
