<?php

declare(strict_types=1);

namespace Whity\Sdk\Agents;

/**
 * The workspace's AI agents and what they did (SDK 1.75, WHIT-990). Resolve it
 * with `\Whity\app(\Whity\Sdk\Agents\Agents::class)` in a route handler or an
 * MCP tool handler.
 *
 * The host implements it; a plugin never does. It reads as the person whose
 * request it is in, in that person's workspace. Neither is an argument, so a
 * plugin can't read another workspace or see more than that person could:
 *
 * - A person who manages agents (`agents:manage`) sees every agent in the
 *   workspace and the workspace's whole activity, as the Agents screen does.
 * - Anybody else sees only the agents they use (an active assignment) and only
 *   what agents did or proposed on their own behalf.
 * - With no person, in a job for example, every method returns nothing.
 *
 * Read only. Nothing here creates, changes, grants or decides anything: only
 * people do that, on the host's own screens. Activity never carries a call's
 * arguments, which may hold whatever the person's own records hold.
 *
 * Shapes are arrays so new keys can be added in a minor version; read the keys
 * you need and ignore the rest.
 */
interface Agents
{
    /** Every status an activity row can have. */
    public const STATUSES = ['done', 'undone', 'waiting', 'declined', 'expired', 'failed'];

    /**
     * The agents the person may see, by name.
     *
     * @param bool $includeArchived Archived agents too (managers only; ignored for others).
     * @return list<array{
     *     id: int,
     *     slug: string,
     *     name: array{en: string, ar: string},
     *     role: array{en: string, ar: string},
     *     toolsets: list<string>,
     *     status: 'active'|'archived',
     *     paused: bool,
     *     presetKey: string|null,
     *     createdAt: string,
     *     updatedAt: string
     * }>
     */
    public function list(bool $includeArchived = false): array;

    /**
     * One agent the person may see, or null (another workspace's id, an unknown id,
     * or one they don't use and don't manage).
     *
     * @return array{
     *     id: int,
     *     slug: string,
     *     name: array{en: string, ar: string},
     *     role: array{en: string, ar: string},
     *     toolsets: list<string>,
     *     status: 'active'|'archived',
     *     paused: bool,
     *     presetKey: string|null,
     *     createdAt: string,
     *     updatedAt: string
     * }|null
     */
    public function find(int $agentId): ?array;

    /**
     * What agents did and proposed, newest first.
     *
     * `kind` is `action` (it ran) or `proposal` (it didn't run: waiting, declined,
     * expired, or approved and then refused by a check). `status` is one of
     * {@see self::STATUSES}. Times are UTC, ISO 8601.
     *
     * @param int|null                $agentId Only this agent's rows.
     * @param string|null             $status  Only rows with this status, one of {@see self::STATUSES}.
     * @param \DateTimeInterface|null $since   Only rows from this time on.
     * @param int                     $limit   1 to 200.
     * @return list<array{
     *     id: string,
     *     kind: 'action'|'proposal',
     *     status: string,
     *     agentId: int|null,
     *     agentName: string|null,
     *     personId: int,
     *     tool: string,
     *     summary: string,
     *     permissions: list<string>,
     *     undoableUntil: string|null,
     *     createdAt: string
     * }>
     */
    public function activity(?int $agentId = null, ?string $status = null, ?\DateTimeInterface $since = null, int $limit = 50, int $offset = 0): array;
}
