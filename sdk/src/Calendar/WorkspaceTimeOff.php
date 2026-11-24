<?php

declare(strict_types=1);

namespace Whity\Sdk\Calendar;

/**
 * Time off on core's calendar in the workspace the call runs in (SDK 1.161,
 * decision D2). Get it from {@see \Whity\Sdk\Context\PluginScope::timeOff()}.
 *
 * The same service as {@see TimeOff}, without the workspace argument: the host
 * uses the workspace the request or job runs in, so a call can't name another.
 * Who may, times, your reference and refusals are exactly as {@see TimeOff}
 * documents them. Without a workspace every call throws \RuntimeException.
 */
interface WorkspaceTimeOff
{
    /**
     * Put time off on a person's calendar, as `$actingProfileId`.
     *
     * @return int the time-off id
     * @throws TimeOffRejected
     */
    public function create(
        int $profileId,
        string $startUtc,
        string $endUtc,
        string $reason,
        int $actingProfileId,
        ?string $externalRef = null,
    ): int;

    /**
     * Remove time off, as `$actingProfileId`. False when there is no such time off.
     *
     * @throws TimeOffRejected when the acting person may not change that calendar
     */
    public function cancel(int $timeOffId, int $actingProfileId): bool;

    /**
     * A person's time off touching `[fromUtc, toUtc)`, earliest first, in UTC.
     *
     * @return list<array{id: int, profileId: int, startUtc: string, endUtc: string, reason: string, externalRef: ?string}>
     * @throws TimeOffRejected for times that are not instants with an offset
     */
    public function forPerson(int $profileId, string $fromUtc, string $toUtc): array;

    /**
     * The time off carrying the plugin's reference, or null.
     *
     * @return array{id: int, profileId: int, startUtc: string, endUtc: string, reason: string, externalRef: ?string}|null
     */
    public function byExternalRef(string $externalRef): ?array;
}
