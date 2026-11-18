<?php

declare(strict_types=1);

namespace Whity\Sdk\Delegation;

/**
 * Whether a person is away at a moment, and who decides for them
 * (SDK 1.120, WHIT-1210, issue #2132).
 *
 * A host service: resolve it with `\Whity\app(\Whity\Sdk\Delegation\Away::class)`;
 * null on a host older than 1.120. Read only: a plugin can never hand over
 * someone's decisions.
 *
 * A person is away when either covers the moment:
 *  - their "I'm away" period (Settings › Delegations), which names a stand-in
 *    and what goes to them; it runs from its start day up to (not including)
 *    the day they are back, read as UTC days;
 *  - time off on their calendar ({@see \Whity\Sdk\Calendar\TimeOff}), which
 *    names no stand-in.
 * When both do, the "I'm away" period is the answer, with the time off's id.
 *
 * `covers` lists what goes to the stand-in: `flows`, `proposals`, `documents`
 * and `plugins` (a plugin's own approvals). A plugin routes an approval to the
 * stand-in only when `covers` holds `plugins`; otherwise it waits for the
 * person. Either way the plugin still checks the stand-in's own permissions:
 * anything above what they may approve waits for the person.
 */
interface Away
{
    public const COVER_FLOWS = 'flows';
    public const COVER_PROPOSALS = 'proposals';
    public const COVER_DOCUMENTS = 'documents';
    public const COVER_PLUGINS = 'plugins';

    /**
     * The person's away period covering `$atUtc` (an instant with an offset
     * or `Z`), or null when they are in. `from` and `until` are UTC instants,
     * `until` exclusive. `standInProfileId` is null for time off without an
     * "I'm away" period. `source` is `away` or `time_off`.
     *
     * @return array{from: string, until: string, standInProfileId: ?int, covers: list<string>, source: string, timeOffId: ?int}|null
     * @throws \InvalidArgumentException when `$atUtc` is not an instant with an offset
     */
    public function at(int $tenantId, int $profileId, string $atUtc): ?array;
}
