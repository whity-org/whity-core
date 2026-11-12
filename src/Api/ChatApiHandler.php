<?php

declare(strict_types=1);

namespace Whity\Api;

use Whity\Core\Chat\ChatException;
use Whity\Core\Chat\ChatService;
use Whity\Core\Chat\ChatViewer;
use Whity\Core\Request;
use Whity\Core\Response;
use Whity\Core\Tenant\TenantContext;
use Whity\Http\JsonBody;
use Whity\Mcp\Auth\McpPrincipal;

/**
 * Team chat, `/api/chat/…` (CH.1, CH.2).
 *
 * Gated on `chat:read` / `chat:write` at the router; membership is decided
 * here, by {@see ChatService}: a chat the caller is not in is "not found".
 *
 * Agents reach the read and post routes as MCP tools. An MCP call carries the
 * agent (`agent_principal_id`), and the agent sees only the chats it is a
 * member of for its person, from its read-from mark. An agent principal that is
 * not a workspace agent sees no chats at all.
 */
final class ChatApiHandler
{
    public function __construct(private readonly ChatService $chat)
    {
    }

    /** @param array<string, string> $params */
    public function list(Request $request, array $params = []): Response
    {
        return $this->guard($request, fn (int $t, ChatViewer $v): Response => Response::json(['data' => $this->chat->list($t, $v)]));
    }

    /** @param array<string, string> $params */
    public function show(Request $request, array $params = []): Response
    {
        return $this->guard($request, fn (int $t, ChatViewer $v): Response => Response::json(['data' => $this->chat->show($t, $v, (int) ($params['id'] ?? 0))]));
    }

    /** @param array<string, string> $params */
    public function messages(Request $request, array $params = []): Response
    {
        return $this->guard($request, function (int $t, ChatViewer $v) use ($request, $params): Response {
            $q = MessagesApiHandler::query($request);
            $after = isset($q['after']) && ctype_digit($q['after']) ? (int) $q['after'] : null;
            $before = isset($q['before']) && ctype_digit($q['before']) ? (int) $q['before'] : null;
            $limit = isset($q['limit']) && ctype_digit($q['limit']) ? (int) $q['limit'] : ChatService::PAGE;

            return Response::json(['data' => $this->chat->messages($t, $v, (int) ($params['id'] ?? 0), $after, $before, $limit)]);
        });
    }

    /** Start a group, or open (start if new) a direct chat with a person or one of your agents. */
    /** @param array<string, string> $params */
    public function create(Request $request, array $params = []): Response
    {
        return $this->guard($request, function (int $t, ChatViewer $v) use ($request): Response {
            $body = JsonBody::parsed($request);
            $type = (string) ($body['type'] ?? 'group');
            if ($type === 'direct') {
                $id = isset($body['agent_id'])
                    ? $this->chat->directWithAgent($t, $v, (int) $body['agent_id'])
                    : $this->chat->directWithPerson($t, $v, (int) ($body['profile_id'] ?? 0));
            } elseif ($type === 'group') {
                $people = is_array($body['people'] ?? null) ? array_values($body['people']) : [];
                $id = $this->chat->createGroup($t, $v, (string) ($body['title'] ?? ''), array_values(array_map('intval', array_filter($people, 'is_numeric'))), self::linkOf($body['link'] ?? null));
            } else {
                return Response::error('type is group or direct.', 422);
            }

            return Response::json(['data' => $this->chat->show($t, $v, $id)], 201);
        });
    }

    /** @param array<string, string> $params */
    public function update(Request $request, array $params = []): Response
    {
        return $this->guard($request, function (int $t, ChatViewer $v) use ($request, $params): Response {
            $body = JsonBody::parsed($request);
            $id = (int) ($params['id'] ?? 0);
            $this->chat->update(
                $t,
                $v,
                $id,
                array_key_exists('title', $body) ? (string) $body['title'] : null,
                array_key_exists('link', $body),
                self::linkOf($body['link'] ?? null),
            );

            return Response::json(['data' => $this->chat->show($t, $v, $id)]);
        });
    }

    /** @param array<string, string> $params */
    public function addMember(Request $request, array $params = []): Response
    {
        return $this->guard($request, function (int $t, ChatViewer $v) use ($request, $params): Response {
            $body = JsonBody::parsed($request);
            $id = (int) ($params['id'] ?? 0);
            if (isset($body['agent_id'])) {
                $this->chat->addAgent(
                    $t,
                    $v,
                    $id,
                    (int) $body['agent_id'],
                    (int) ($body['works_for'] ?? $v->profileId),
                    (string) ($body['reads'] ?? 'now'),
                    is_array($body['actions'] ?? null) ? $body['actions'] : [],
                );
            } else {
                $this->chat->addPerson($t, $v, $id, (int) ($body['profile_id'] ?? 0));
            }

            return Response::json(['data' => $this->chat->show($t, $v, $id)], 201);
        });
    }

    /** @param array<string, string> $params */
    public function removeMember(Request $request, array $params = []): Response
    {
        return $this->guard($request, function (int $t, ChatViewer $v) use ($params): Response {
            $id = (int) ($params['id'] ?? 0);
            $this->chat->removeMember($t, $v, $id, (int) ($params['member'] ?? 0));

            return Response::json(['data' => ['removed' => true]]);
        });
    }

    /** @param array<string, string> $params */
    public function post(Request $request, array $params = []): Response
    {
        return $this->guard($request, function (int $t, ChatViewer $v) use ($request, $params): Response {
            $body = JsonBody::parsed($request);
            $mentions = is_array($body['mentions'] ?? null) ? array_values(array_filter($body['mentions'], 'is_array')) : [];

            return Response::json(['data' => $this->chat->post(
                $t,
                $v,
                (int) ($params['id'] ?? 0),
                (string) ($body['body'] ?? ''),
                $mentions,
                ($body['style'] ?? null) === 'line' ? 'line' : 'message',
            )], 201);
        });
    }

    /** @param array<string, string> $params */
    public function shareProposal(Request $request, array $params = []): Response
    {
        return $this->guard($request, function (int $t, ChatViewer $v) use ($request, $params): Response {
            $body = JsonBody::parsed($request);

            return Response::json(['data' => $this->chat->shareProposal(
                $t,
                $v,
                (int) ($params['id'] ?? 0),
                (int) ($body['proposal_id'] ?? 0),
                self::agentPrincipalId($request) ?? '',
                (string) ($body['body'] ?? ''),
            )], 201);
        });
    }

    /** @param array<string, string> $params */
    public function markRead(Request $request, array $params = []): Response
    {
        return $this->guard($request, function (int $t, ChatViewer $v) use ($request, $params): Response {
            $body = JsonBody::parsed($request);
            $this->chat->markRead($t, $v, (int) ($params['id'] ?? 0), (int) ($body['message_id'] ?? 0));

            return Response::json(['data' => ['read' => true]]);
        });
    }

    /** @param array<string, string> $params */
    public function approve(Request $request, array $params = []): Response
    {
        return $this->guard($request, fn (int $t, ChatViewer $v): Response => Response::json(['data' => $this->chat->decideHeld($t, $v, (int) ($params['id'] ?? 0), true)]));
    }

    /** @param array<string, string> $params */
    public function decline(Request $request, array $params = []): Response
    {
        return $this->guard($request, fn (int $t, ChatViewer $v): Response => Response::json(['data' => $this->chat->decideHeld($t, $v, (int) ($params['id'] ?? 0), false)]));
    }

    /** @param array<string, string> $params */
    public function directory(Request $request, array $params = []): Response
    {
        return $this->guard($request, function (int $t) use ($request): Response {
            $q = MessagesApiHandler::query($request);

            return Response::json(['data' => $this->chat->directory($t, (string) ($q['q'] ?? ''))]);
        });
    }

    /**
     * Runs `$fn(tenantId, viewer)` with the workspace and the caller bound.
     *
     * @param callable(int, ChatViewer): Response $fn
     */
    private function guard(Request $request, callable $fn): Response
    {
        $tenantId = TenantContext::getTenantId();
        $user = $request->user;
        $profileId = is_object($user) ? ($user->profile_id ?? null) : null;
        if ($tenantId === null || !is_int($profileId) || $profileId <= 0) {
            return Response::error('Tenant context is required', 403);
        }

        try {
            $viewer = ChatViewer::person($profileId);
            if (($user->principal_kind ?? null) === McpPrincipal::KIND_AGENT) {
                $principal = self::agentPrincipalId($request);
                $agent = $principal === null ? null : $this->chat->agentViewer($tenantId, $principal);
                // Not a workspace agent (or not this person's): it is in no chat.
                $viewer = $agent !== null && $agent->profileId === $profileId ? $agent : ChatViewer::agent(0, $profileId, '');
            } elseif (!$this->chat->repository()->isStaff($tenantId, $profileId)) {
                return Response::error('Team chat is for the people who work here.', 403);
            }

            return $fn($tenantId, $viewer);
        } catch (ChatException $e) {
            return Response::error($e->plain(), $e->status());
        } catch (\Throwable $e) {
            error_log('[ChatApiHandler] request failed: ' . $e->getMessage());

            return Response::error('Something went wrong with Chat. Please try again.', 500);
        }
    }

    private static function agentPrincipalId(Request $request): ?string
    {
        $user = $request->user;
        $id = is_object($user) ? ($user->agent_principal_id ?? null) : null;

        return is_string($id) && $id !== '' ? $id : null;
    }

    /** @return array<string, mixed>|null */
    private static function linkOf(mixed $link): ?array
    {
        return is_array($link) ? $link : null;
    }
}
