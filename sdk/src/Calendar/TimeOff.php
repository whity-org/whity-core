<?php

declare(strict_types=1);

namespace Whity\Sdk\Calendar;

/**
 * Time off on core's calendar, for a plugin that runs its own leave requests
 * (SDK 1.120, WHIT-1210, issue #2132).
 *
 * A host service: resolve it with `\Whity\app(\Whity\Sdk\Calendar\TimeOff::class)`;
 * null on a host older than 1.120.
 *
 * What it writes is real time off, the same as Calendar › Time off: the person
 * shows as away in bookings (no free times), in team views and in "who's in",
 * and {@see \Whity\Sdk\Delegation\Away::at()} sees it. A busy event from
 * {@see CalendarEvents::upsertExternal()} is not time off.
 *
 * WHO MAY. Writes run as `$actingProfileId`, with the same check as the HTTP
 * route: any member of staff may put time off on their own calendar (since
 * 1.130; before, they needed `calendar:write`); anybody else's needs
 * `calendar:write` and `calendar:manage` in that workspace. An approver who
 * lacks those can get them for a plugin role through
 * {@see \Whity\Sdk\Contribute\CorePermissionRequestsInterface}. A leave plugin passes the person who
 * gave the final approval (HR, say). The person must be a member of staff of
 * the workspace.
 *
 * TIMES. `startUtc` and `endUtc` are instants with an offset or `Z`, such as
 * `2026-11-22T00:00:00+03:00`; the end is exclusive. For whole days, pass the
 * person's local midnight to the midnight after the last day. At most a year.
 *
 * YOUR REFERENCE. Pass your own `externalRef` (`keyhub.leave:123`, up to 255
 * characters) and `create()` is idempotent: while time off with that reference
 * exists in the workspace, creating it again returns the same id (moving it to
 * the new times and reason if they changed) and never adds a second. Find it
 * again with {@see self::byExternalRef()} and cancel it when the request is
 * withdrawn. Once cancelled, the reference is free again.
 *
 * WHICH WORKSPACE. Pass the workspace the caller is already authorized in
 * (a route handler's `Request::getWorkspaceId()`), never one from a request
 * body.
 *
 * Refusals throw {@see TimeOffRejected} with a stable reason; nothing is
 * written then.
 */
interface TimeOff
{
    /**
     * Put time off on a person's calendar, as `$actingProfileId`.
     *
     * @return int the time-off id
     * @throws TimeOffRejected
     */
    public function create(
        int $tenantId,
        int $profileId,
        string $startUtc,
        string $endUtc,
        string $reason,
        int $actingProfileId,
        ?string $externalRef = null,
    ): int;

    /**
     * Remove time off, as `$actingProfileId`. False when there is no such
     * time off (already cancelled, another workspace's, or not time off), so a
     * retry is safe.
     *
     * @throws TimeOffRejected when the acting person may not change that calendar
     */
    public function cancel(int $tenantId, int $timeOffId, int $actingProfileId): bool;

    /**
     * A person's time off touching `[fromUtc, toUtc)`, earliest first.
     * Times come back in UTC (`2026-11-21T21:00:00Z`).
     *
     * @return list<array{id: int, profileId: int, startUtc: string, endUtc: string, reason: string, externalRef: ?string}>
     * @throws TimeOffRejected for times that are not instants with an offset
     */
    public function forPerson(int $tenantId, int $profileId, string $fromUtc, string $toUtc): array;

    /**
     * The time off carrying the plugin's reference, or null.
     *
     * @return array{id: int, profileId: int, startUtc: string, endUtc: string, reason: string, externalRef: ?string}|null
     */
    public function byExternalRef(int $tenantId, string $externalRef): ?array;
}
