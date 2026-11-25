<?php

declare(strict_types=1);

namespace Whity\Core\Plugin;

/**
 * The workspace a context-bound service acts in (SDK 1.161, decision D2): read
 * at each call from the closure the scope passes, never from the caller.
 */
trait PinnedWorkspace
{
    /** @var \Closure(): ?int */
    private readonly \Closure $workspace;

    private function workspace(): int
    {
        $workspace = ($this->workspace)();
        if ($workspace === null) {
            throw new \RuntimeException(self::SERVICE . ' needs a workspace, and this call has none.');
        }

        return $workspace;
    }
}
