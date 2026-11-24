<?php

declare(strict_types=1);

namespace Whity\Sdk\Delegation;

/**
 * Whether a person is away, in the workspace the call runs in (SDK 1.161,
 * decision D2). Get it from {@see \Whity\Sdk\Context\PluginScope::away()}.
 *
 * The same service as {@see Away}, without the workspace argument: the host
 * uses the workspace the request or job runs in, so a call can't name another.
 * Read only, and the answer is exactly as {@see Away::at()} documents it.
 * Without a workspace the call throws \RuntimeException.
 */
interface WorkspaceAway
{
    /**
     * The person's away period covering `$atUtc`, or null when they are in.
     *
     * @return array{from: string, until: string, standInProfileId: ?int, covers: list<string>, source: string, timeOffId: ?int}|null
     * @throws \InvalidArgumentException when `$atUtc` is not an instant with an offset
     */
    public function at(int $profileId, string $atUtc): ?array;
}
