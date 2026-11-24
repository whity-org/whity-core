<?php

declare(strict_types=1);

namespace Whity\Core\Plugin;

use Whity\Sdk\Delegation\Away;
use Whity\Sdk\Delegation\WorkspaceAway;

/** {@see Away} pinned to the workspace the call runs in (SDK 1.161, D2). */
final class HostWorkspaceAway implements WorkspaceAway
{
    use PinnedWorkspace;

    private const SERVICE = 'Away';

    /** @param \Closure(): ?int $workspace */
    public function __construct(private readonly Away $away, \Closure $workspace)
    {
        $this->workspace = $workspace;
    }

    public function at(int $profileId, string $atUtc): ?array
    {
        return $this->away->at($this->workspace(), $profileId, $atUtc);
    }
}
