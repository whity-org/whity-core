<?php

declare(strict_types=1);

namespace Whity\Api;

use Throwable;
use Whity\Core\Agent\AgentAssignmentRepository;
use Whity\Core\Agent\AgentProposalRepository;
use Whity\Sdk\Inbox\InboxItem;
use Whity\Core\Inbox\InboxSourceRegistry;
use Whity\Core\Request;
use Whity\Core\Response;
use Whity\Core\Tenant\TenantContext;

/**
 * What is waiting for the caller, in one list (WHIT-872, design 2.9 H.2): every
 * inbox source and their agent proposals.
 *
 *   GET /api/me/waiting?limit=6
 *
 * `GET /api/me/inbox` refuses an unsourced request until someone decides the three
 * questions #881 names for an aggregate. This route is that decision, and that
 * endpoint keeps its contract:
 *
 *  1. ORDER: oldest first, across every source. H.2 decides it: what has waited
 *     longest leads.
 *  2. FAILURE: one source failing doesn't blank the list. Its key is named in
 *     `unavailable` and the rest is served, so a client can say the list is
 *     incomplete rather than show "nothing is waiting".
 *  3. PAGES: none. It answers with the oldest `limit` rows and the real totals. A
 *     person who wants the rest opens Inbox or Approvals, which page each source.
 *
 * Session-gated and self-scoped like the rest of `/api/me`: every read takes the
 * caller's own tenant and profile, so it can answer for nobody else. It stays off
 * the MCP surface because it carries proposals, which an agent must not read back
 * or decide ({@see AgentProposalsApiHandler}).
 */
final class MeWaitingApiHandler
{
    private const DEFAULT_LIMIT = 6;
    private const MAX_LIMIT = 50;

    /** The name proposals go by in `unavailable`, beside the inbox source keys. */
    private const PROPOSALS_KEY = 'agent_proposals';

    public function __construct(
        private readonly InboxSourceRegistry $sources,
        private readonly AgentProposalRepository $proposals,
        private readonly ?AgentAssignmentRepository $assignments = null,
    ) {
    }

    /** @param array<string, string> $params */
    public function list(Request $request, array $params = []): Response
    {
        $tenantId = TenantContext::getTenantId();
        $actor    = $request->user;
        $profile  = is_object($actor) && isset($actor->profile_id) && is_int($actor->profile_id) ? $actor->profile_id : null;
        if ($tenantId === null || $profile === null) {
            return Response::error('Unauthenticated', 401);
        }

        parse_str((string) parse_url($request->getPath(), PHP_URL_QUERY), $query);
        $requested = $query['limit'] ?? $_GET['limit'] ?? null;
        $limit = is_string($requested) && ctype_digit($requested)
            ? max(1, min((int) $requested, self::MAX_LIMIT))
            : self::DEFAULT_LIMIT;

        $rows = [];
        $unavailable = [];
        $inboxTotal = 0;
        $proposalTotal = 0;

        foreach ($this->sources->keys() as $key) {
            $source = $this->sources->get($key);
            if ($source === null) {
                continue;
            }
            try {
                $total = $source->count($tenantId, $profile, true);
                // A source lists newest first, so its oldest rows are its last page.
                $items = $source->list($tenantId, $profile, true, $limit, max(0, $total - $limit));
            } catch (Throwable $e) {
                error_log("[MeWaitingApiHandler] inbox source {$key} failed: " . $e->getMessage());
                $unavailable[] = $key;
                continue;
            }
            $inboxTotal += $total;
            foreach ($items as $item) {
                $rows[] = self::inboxRow($key, $item);
            }
        }

        try {
            $proposalTotal = $this->proposals->countWaiting($tenantId, $profile);
            $waiting = $this->proposals->oldestWaiting($tenantId, $profile, $limit);
            $principals = array_map(static fn (array $row): string => (string) $row['agent_principal_id'], $waiting);
            $names = $this->proposals->agentNames($tenantId, $principals);
            $paused = $this->assignments?->pausedPrincipals($principals, $tenantId) ?? [];
            foreach ($waiting as $proposal) {
                $rows[] = [
                    'key'      => 'proposal:' . $proposal['id'],
                    'kind'     => 'proposal',
                    'at'       => (string) $proposal['created_at'],
                    'proposal' => AgentProposalsApiHandler::present(
                        $proposal,
                        $names,
                        null,
                        in_array((string) $proposal['agent_principal_id'], $paused, true)
                    ),
                ];
            }
        } catch (Throwable $e) {
            error_log('[MeWaitingApiHandler] agent proposals failed: ' . $e->getMessage());
            $unavailable[] = self::PROPOSALS_KEY;
        }

        usort($rows, static function (array $a, array $b): int {
            return [(int) strtotime($a['at']), $a['key']] <=> [(int) strtotime($b['at']), $b['key']];
        });

        return Response::json([
            'data'        => array_slice($rows, 0, $limit),
            'counts'      => ['inbox' => $inboxTotal, 'proposals' => $proposalTotal, 'total' => $inboxTotal + $proposalTotal],
            'unavailable' => $unavailable,
        ], 200);
    }

    /** @return array{key: string, kind: string, at: string, source: string, item: array<string, mixed>} */
    private static function inboxRow(string $source, InboxItem $item): array
    {
        return [
            'key'    => $source . ':' . $item->id,
            'kind'   => 'inbox',
            'at'     => $item->timestamp,
            'source' => $source,
            'item'   => $item->toArray(),
        ];
    }
}
