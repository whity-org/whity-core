<?php

declare(strict_types=1);

namespace Whity\Core\Plugin;

use Whity\Sdk\Secrets\SecretStore;
use Whity\Sdk\Secrets\WorkspaceSecrets;

/**
 * A plugin's {@see SecretStore} pinned to the workspace the call runs in
 * (SDK 1.124, decision D2). The workspace is read at each call, never passed.
 */
final class HostWorkspaceSecrets implements WorkspaceSecrets
{
    /** @param \Closure(): ?int $workspace */
    public function __construct(
        private readonly SecretStore $store,
        private readonly \Closure $workspace,
    ) {
    }

    public function set(string $key, string $value): void
    {
        $this->store->set($this->workspace(), $key, $value);
    }

    public function get(string $key): ?string
    {
        return $this->store->get($this->workspace(), $key);
    }

    public function delete(string $key): void
    {
        $this->store->delete($this->workspace(), $key);
    }

    private function workspace(): int
    {
        $workspace = ($this->workspace)();
        if ($workspace === null) {
            throw new \RuntimeException('Plugin secrets need a workspace, and this call has none.');
        }

        return $workspace;
    }
}
