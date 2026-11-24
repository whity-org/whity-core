<?php

declare(strict_types=1);

namespace Whity\Core\Plugin;

use Whity\Sdk\Calendar\TimeOff;
use Whity\Sdk\Calendar\WorkspaceTimeOff;

/** {@see TimeOff} pinned to the workspace the call runs in (SDK 1.161, D2). */
final class HostWorkspaceTimeOff implements WorkspaceTimeOff
{
    use PinnedWorkspace;

    private const SERVICE = 'Time off';

    /** @param \Closure(): ?int $workspace */
    public function __construct(private readonly TimeOff $timeOff, \Closure $workspace)
    {
        $this->workspace = $workspace;
    }

    public function create(
        int $profileId,
        string $startUtc,
        string $endUtc,
        string $reason,
        int $actingProfileId,
        ?string $externalRef = null,
    ): int {
        return $this->timeOff->create($this->workspace(), $profileId, $startUtc, $endUtc, $reason, $actingProfileId, $externalRef);
    }

    public function cancel(int $timeOffId, int $actingProfileId): bool
    {
        return $this->timeOff->cancel($this->workspace(), $timeOffId, $actingProfileId);
    }

    public function forPerson(int $profileId, string $fromUtc, string $toUtc): array
    {
        return $this->timeOff->forPerson($this->workspace(), $profileId, $fromUtc, $toUtc);
    }

    public function byExternalRef(string $externalRef): ?array
    {
        return $this->timeOff->byExternalRef($this->workspace(), $externalRef);
    }
}
