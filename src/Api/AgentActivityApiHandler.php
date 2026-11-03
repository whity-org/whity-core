<?php

declare(strict_types=1);

namespace Whity\Api;

use Whity\Core\Agent\AgentActivityRepository;
use Whity\Core\Agent\AgentProposalRepository;
use Whity\Core\Request;
use Whity\Core\Response;
use Whity\Core\Tenant\TenantContext;

/**
 * What the workspace's agents did and proposed (WHIT-867, design 2.9 S6).
 *
 *   GET /api/agent-activity   newest first; ?agent=<id>&status=&since=&limit=&offset=
 *
 * Gated on `agents:manage` at the router, and every row is the caller's tenant's:
 * an agent id from another workspace finds nothing, the same as one that never
 * acted.
 *
 * It says what happened, for whom and with which permission, and never the
 * arguments: those may hold whatever the person's own records hold, and managing
 * agents doesn't grant reading them. Only the person an action was for can undo
 * it, through /api/me/agent-actions, so no row offers that here.
 *
 * Not on the MCP surface (`expose: false`): an agent doesn't read what other
 * agents did for other people.
 */
final class AgentActivityApiHandler
{
    private const DEFAULT_LIMIT = 50;
    private const MAX_LIMIT     = 200;

    /**
     * @param \Closure(): array<string, array<string, mixed>> $toolInfo
     *        Each tool in plain words, by name ({@see \Whity\Mcp\Tools\ToolCatalogue}).
     */
    public function __construct(
        private readonly AgentActivityRepository $activity,
        private readonly AgentProposalRepository $proposals,
        private readonly \Closure $toolInfo,
    ) {
    }

    /** @param array<string, string> $params */
    public function list(Request $request, array $params = []): Response
    {
        $tenantId = TenantContext::getTenantId();
        $actor    = $request->user;
        if ($tenantId === null || !is_object($actor) || !isset($actor->profile_id)) {
            return Response::error('Unauthenticated', 401);
        }

        parse_str((string) parse_url($request->getPath(), PHP_URL_QUERY), $query);
        $query += $_GET;

        $status = is_string($query['status'] ?? null) && in_array($query['status'], AgentActivityRepository::STATUSES, true) ? $query['status'] : null;
        $since  = is_string($query['since'] ?? null) && ($time = strtotime($query['since'])) !== false ? gmdate('Y-m-d H:i:s', $time) : null;
        $agent  = is_string($query['agent'] ?? null) && ctype_digit($query['agent']) ? (int) $query['agent'] : null;
        $limit  = is_string($query['limit'] ?? null) && ctype_digit($query['limit']) ? max(1, min((int) $query['limit'], self::MAX_LIMIT)) : self::DEFAULT_LIMIT;
        $offset = is_string($query['offset'] ?? null) && ctype_digit($query['offset']) ? (int) $query['offset'] : 0;

        // Waiting proposals past their expiry read as expired, here as in the inbox.
        $this->proposals->expireDue($tenantId);

        $rows    = $this->activity->forWorkspace($tenantId, $agent, $status, $since, $limit + 1, $offset);
        $hasMore = count($rows) > $limit;
        $tools   = ($this->toolInfo)();

        return Response::json([
            'data' => array_map(
                static fn (array $row): array => self::present($row, $tools[$row['tool']] ?? null),
                array_slice($rows, 0, $limit),
            ),
            'hasMore' => $hasMore,
        ], 200);
    }

    /**
     * @param array<string, mixed>      $row
     * @param array<string, mixed>|null $tool
     * @return array<string, mixed>
     */
    private static function present(array $row, ?array $tool): array
    {
        return [
            'id'                => $row['kind'] . '-' . $row['id'],
            'kind'              => $row['kind'],
            'status'            => $row['status'],
            'agent'             => ['id' => $row['agent_id'], 'name' => $row['agent_name'], 'nameAr' => $row['agent_name_ar']],
            'person'            => ['id' => $row['on_behalf_of_profile_id'], 'name' => $row['person_name']],
            'tool'              => $row['tool'],
            'toolInfo'          => $tool,
            'summary'           => $row['summary'],
            'permissions'       => $row['permissions'],
            'undoWindowSeconds' => $row['undo_window_seconds'],
            'undoableUntil'     => $row['undoable_until'] !== null ? self::utc($row['undoable_until']) : null,
            'createdAt'         => self::utc($row['created_at']),
        ];
    }

    private static function utc(string $time): string
    {
        return gmdate('Y-m-d\TH:i:s\Z', (int) strtotime($time . ' UTC'));
    }
}
