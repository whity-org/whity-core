<?php

declare(strict_types=1);

namespace Whity\Api;

use Whity\Core\Chat\ChatException;
use Whity\Core\Chat\ChatInbox;
use Whity\Core\Chat\ChatService;
use Whity\Core\Chat\ChatViewer;
use Whity\Core\Request;
use Whity\Core\RBAC\CorePermissions;
use Whity\Core\Response;
use Whity\Http\UploadedFilePart;
use Whity\Http\UploadedFilePartException;
use Whity\Core\Tenant\TenantContext;
use Whity\Http\JsonBody;
use Whity\Mcp\Auth\McpPrincipal;
use Whity\Sdk\Rbac\PermissionResolver;

/**
 * Team chat, `/api/chat/…` (CH.1, CH.2).
 *
 * Ungated at the router, like the Inbox: only staff of the workspace get in
 * (customers are refused), and membership is decided by {@see ChatService}:
 * a chat the caller is not in is "not found".
 *
 * Agents reach the read and post routes as MCP tools. An MCP call carries the
 * agent (`agent_principal_id`), and the agent sees only the chats it is a
 * member of for its person, from its read-from mark. An agent principal that is
 * not a workspace agent sees no chats at all.
 */
final class ChatApiHandler
{
    public function __construct(
        private readonly ChatService $chat,
        private readonly ?ChatInbox $inbox = null,
        private readonly ?PermissionResolver $permissions = null,
    ) {
    }

    /**
     * One chat (UC.1): the viewer's team chats and, when they may read customer
     * conversations, the workspace's customer conversations, in one list.
     *
     * @param array<string, string> $params
     */
    public function inbox(Request $request, array $params = []): Response
    {
        return $this->guard($request, function (int $t, ChatViewer $v): Response {
            $customers = $this->readsCustomers($t, $v);

            return Response::json(['data' => $this->theInbox()->list($t, $v, $customers), 'customers' => $customers]);
        });
    }

    /** @param array<string, string> $params */
    public function pin(Request $request, array $params = []): Response
    {
        return $this->guard($request, function (int $t, ChatViewer $v) use ($request, $params): Response {
            if ($v->isAgent()) {
                return Response::error('Agents can\'t pin chats.', 403);
            }
            $pinned = (JsonBody::parsed($request)['pinned'] ?? true) !== false;
            $this->theInbox()->pin($t, $v, (int) ($params['id'] ?? 0), $pinned, $this->readsCustomers($t, $v));

            return Response::json(['data' => ['pinned' => $pinned]]);
        });
    }

    /**
     * How far the viewer has read a customer conversation in Chat.
     *
     * @param array<string, string> $params
     */
    public function readCustomer(Request $request, array $params = []): Response
    {
        return $this->guard($request, function (int $t, ChatViewer $v) use ($request, $params): Response {
            if ($v->isAgent()) {
                return Response::error('Chat not found', 404);
            }
            $messageId = (int) (JsonBody::parsed($request)['message_id'] ?? 0);
            $this->theInbox()->readCustomer($t, $v, (int) ($params['id'] ?? 0), $messageId, $this->readsCustomers($t, $v));

            return Response::json(['data' => ['ok' => true]]);
        });
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
                self::positiveInt($body['reply_to'] ?? null),
                self::positiveInt($body['thread_root'] ?? null),
                ($body['also_to_chat'] ?? false) === true,
                is_array($body['attachments'] ?? null) ? array_values(array_filter($body['attachments'], 'is_int')) : [],
                $body['block'] ?? null,
            )], 201);
        });
    }

    // ── The parts of a message (UC.6–UC.12) ──────────────────────────────────

    /** @param array<string, string> $params */
    public function edit(Request $request, array $params = []): Response
    {
        return $this->guard($request, function (int $t, ChatViewer $v) use ($request, $params): Response {
            $body = JsonBody::parsed($request);

            return Response::json(['data' => $this->chat->edit($t, $v, (int) ($params['id'] ?? 0), (string) ($body['body'] ?? ''))]);
        });
    }

    /** @param array<string, string> $params */
    public function deleteMessage(Request $request, array $params = []): Response
    {
        return $this->guard($request, fn (int $t, ChatViewer $v): Response => Response::json(['data' => $this->chat->delete($t, $v, (int) ($params['id'] ?? 0))]));
    }

    /** @param array<string, string> $params */
    public function react(Request $request, array $params = []): Response
    {
        return $this->guard($request, function (int $t, ChatViewer $v) use ($request, $params): Response {
            $body = JsonBody::parsed($request);

            return Response::json(['data' => $this->chat->react($t, $v, (int) ($params['id'] ?? 0), (string) ($body['emoji'] ?? ''))]);
        });
    }

    /** @param array<string, string> $params */
    public function pinMessage(Request $request, array $params = []): Response
    {
        return $this->guard($request, function (int $t, ChatViewer $v) use ($request, $params): Response {
            $pinned = (JsonBody::parsed($request)['pinned'] ?? true) !== false;

            return Response::json(['data' => $this->chat->pinMessage($t, $v, (int) ($params['id'] ?? 0), $pinned)]);
        });
    }

    /** @param array<string, string> $params */
    public function thread(Request $request, array $params = []): Response
    {
        return $this->guard($request, fn (int $t, ChatViewer $v): Response => Response::json(['data' => $this->chat->thread($t, $v, (int) ($params['id'] ?? 0))]));
    }

    /** @param array<string, string> $params */
    public function search(Request $request, array $params = []): Response
    {
        return $this->guard($request, function (int $t, ChatViewer $v) use ($request, $params): Response {
            $q = MessagesApiHandler::query($request);

            return Response::json(['data' => $this->chat->search(
                $t,
                $v,
                (int) ($params['id'] ?? 0),
                (string) ($q['q'] ?? ''),
                self::positiveInt($q['from'] ?? null),
            )]);
        });
    }

    /** @param array<string, string> $params */
    public function state(Request $request, array $params = []): Response
    {
        return $this->guard($request, function (int $t, ChatViewer $v) use ($request, $params): Response {
            $q = MessagesApiHandler::query($request);
            $since = isset($q['since']) && $q['since'] !== '' ? mb_substr($q['since'], 0, 40) : null;

            return Response::json(['data' => $this->chat->state($t, $v, (int) ($params['id'] ?? 0), self::positiveInt($q['after'] ?? null) ?? 0, $since)]);
        });
    }

    /** @param array<string, string> $params */
    public function typing(Request $request, array $params = []): Response
    {
        return $this->guard($request, function (int $t, ChatViewer $v) use ($params): Response {
            $this->chat->typing($t, $v, (int) ($params['id'] ?? 0));

            return Response::json(['data' => ['ok' => true]]);
        });
    }

    // ── Blocks inside messages (UC.5) ────────────────────────────────────────

    /** @param array<string, string> $params */
    public function vote(Request $request, array $params = []): Response
    {
        return $this->guard($request, function (int $t, ChatViewer $v) use ($request, $params): Response {
            $choices = JsonBody::parsed($request)['choices'] ?? [];

            return Response::json(['data' => $this->chat->vote($t, $v, (int) ($params['id'] ?? 0), is_array($choices) ? array_values(array_filter($choices, 'is_int')) : [])]);
        });
    }

    /** @param array<string, string> $params */
    public function records(Request $request, array $params = []): Response
    {
        return $this->guard($request, function (int $t, ChatViewer $v) use ($request): Response {
            $q = MessagesApiHandler::query($request);
            $kind = in_array($q['type'] ?? null, ['booking', 'document'], true) ? (string) $q['type'] : 'booking';

            return Response::json(['data' => $this->chat->findRecords($t, $v, $kind, (string) ($q['q'] ?? ''))]);
        });
    }

    // ── Files, photos and voice (UC.10) ──────────────────────────────────────

    /**
     * Upload one file (multipart field `file`) to a chat; `?duration_ms=`,
     * `?width=` and `?height=` describe a voice message, video or photo.
     *
     * @param array<string, string> $params
     */
    public function upload(Request $request, array $params = []): Response
    {
        return $this->guard($request, function (int $t, ChatViewer $v) use ($request, $params): Response {
            try {
                $part = UploadedFilePart::read($request, 'file');
            } catch (UploadedFilePartException $e) {
                return Response::error($e->clientMessage, $e->status);
            }
            $q = MessagesApiHandler::query($request);

            return Response::json(['data' => $this->chat->upload($t, $v, (int) ($params['id'] ?? 0), $part['bytes'], $part['media_type'], $part['filename'], [
                'duration_ms' => self::positiveInt($q['duration_ms'] ?? null),
                'width' => self::positiveInt($q['width'] ?? null),
                'height' => self::positiveInt($q['height'] ?? null),
            ])], 201);
        });
    }

    /** @param array<string, string> $params */
    public function transcribe(Request $request, array $params = []): Response
    {
        return $this->guard($request, function (int $t, ChatViewer $v) use ($request, $params): Response {
            $text = (string) (JsonBody::parsed($request)['transcript'] ?? '');

            return Response::json(['data' => $this->chat->transcribe($t, $v, (int) ($params['id'] ?? 0), $text)]);
        });
    }

    /**
     * A file's bytes. Photos, videos, voice and PDF show in the browser; the
     * rest download. Never sniffed by the browser, never cached by others.
     *
     * @param array<string, string> $params
     */
    public function file(Request $request, array $params = []): Response
    {
        return $this->guard($request, function (int $t, ChatViewer $v) use ($params): Response {
            $file = $this->chat->file($t, $v, (int) ($params['id'] ?? 0));
            $name = str_replace(['"', '\\', "\r", "\n"], '', $file['filename']);

            return new Response(200, $file['bytes'], [
                'Content-Type' => $file['content_type'],
                'Content-Length' => (string) strlen($file['bytes']),
                'Content-Disposition' => ($file['inline'] ? 'inline' : 'attachment') . '; filename="' . (preg_replace('/[^\x20-\x7e]/', '_', $name) ?? 'file')
                    . '"; filename*=UTF-8\'\'' . rawurlencode($name),
                'X-Content-Type-Options' => 'nosniff',
                'Cache-Control' => 'private, max-age=3600',
            ]);
        });
    }

    /** @param array<string, string> $params */
    public function media(Request $request, array $params = []): Response
    {
        return $this->guard($request, function (int $t, ChatViewer $v) use ($request, $params): Response {
            $kind = MessagesApiHandler::query($request)['kind'] ?? null;

            return Response::json(['data' => $this->chat->media($t, $v, (int) ($params['id'] ?? 0), in_array($kind, ['photos', 'files'], true) ? $kind : null)]);
        });
    }

    /** Bubbles or compact rows, the person's own choice (UC.7). */
    /** @param array<string, string> $params */
    public function preferences(Request $request, array $params = []): Response
    {
        return $this->guard($request, function (int $t, ChatViewer $v) use ($request): Response {
            if ($request->getMethod() === 'PUT') {
                $this->chat->setLayout($v->profileId, (string) (JsonBody::parsed($request)['layout'] ?? ''));
            }

            return Response::json(['data' => ['layout' => $this->chat->layout($v->profileId)]]);
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

    /**
     * POST /api/chat/chats/{id}/focus `{focused: bool}`: the person is looking
     * at this chat in a focused window (sent every ChatPushNotifier::HEARTBEAT
     * seconds), or stopped. No push for that chat while they look (WHIT-1077).
     *
     * @param array<string, string> $params
     */
    public function focus(Request $request, array $params = []): Response
    {
        return $this->guard($request, function (int $t, ChatViewer $v) use ($request, $params): Response {
            $body = JsonBody::parsed($request);
            $focused = ($body['focused'] ?? true) !== false;
            $this->chat->focus($t, $v, (int) ($params['id'] ?? 0), $focused);

            return Response::json(['data' => ['focused' => $focused]]);
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

    private static function positiveInt(mixed $value): ?int
    {
        if (is_int($value)) {
            return $value > 0 ? $value : null;
        }
        if (is_string($value) && ctype_digit($value) && $value !== '0') {
            return (int) $value;
        }

        return null;
    }

    private function theInbox(): ChatInbox
    {
        if ($this->inbox === null) {
            throw new \LogicException('ChatApiHandler was built without its ChatInbox.');
        }

        return $this->inbox;
    }

    /** People who may read the workspace's customer conversations see them in Chat; agents never through Chat. */
    private function readsCustomers(int $tenantId, ChatViewer $viewer): bool
    {
        return !$viewer->isAgent()
            && $this->permissions !== null
            && $this->permissions->hasPermission($viewer->profileId, $tenantId, CorePermissions::MESSAGES_READ);
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
