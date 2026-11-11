<?php

declare(strict_types=1);

namespace Whity\Api;

use Whity\Core\Messaging\ConversationRepository;
use Whity\Core\Messaging\MessageChannelRegistry;
use Whity\Core\Messaging\MessagesPresenter;
use Whity\Core\Messaging\MessagingException;
use Whity\Core\Messaging\MessagingService;
use Whity\Core\Messaging\MessagingSettings;
use Whity\Core\Messaging\Recipient;
use Whity\Core\Messaging\SendOptions;
use Whity\Core\Request;
use Whity\Core\Response;
use Whity\Core\Tenant\TenantContext;
use Whity\Http\JsonBody;

/**
 * The staff side of Messages (ADR 0016 §6): `/api/messages/…`.
 *
 * Every route is gated on `messages:read`, `messages:reply` or
 * `messages:manage` at the router; this handler adds the workspace binding and
 * the checks a permission cannot make (the assignee is staff here).
 *
 * Agents reach these routes as MCP tools. A reply an agent writes is held for
 * {@see MessagingService::AGENT_HOLD_SECONDS} and can be cancelled in that
 * time, which is what lets a person allow agents to reply without each reply
 * becoming irreversible.
 */
final class MessagesApiHandler
{
    private const DEFAULT_LIMIT = 30;
    private const MAX_LIMIT = 100;

    public function __construct(
        private readonly MessagingService $messaging,
        private readonly MessagingSettings $settings,
    ) {
    }

    /** @param array<string, string> $params */
    public function list(Request $request, array $params = []): Response
    {
        return $this->guard($request, function (int $tenantId, int $profileId) use ($request): Response {
            $query = self::query($request);
            $assignee = $query['assignee'] ?? null;
            if (is_string($assignee) && ctype_digit($assignee)) {
                $assignee = (int) $assignee;
            } elseif (!in_array($assignee, ['me', 'none'], true)) {
                $assignee = null;
            }
            $filters = [
                'status' => $query['status'] ?? null,
                'assignee' => $assignee,
                'channel' => $query['channel'] ?? null,
                'q' => $query['q'] ?? null,
                'viewer' => $profileId,
            ];

            $after = null;
            if (isset($query['cursor']) && $query['cursor'] !== '') {
                $after = self::decodeCursor($query['cursor']);
                if ($after === null) {
                    return Response::error("'cursor' is not one this endpoint returned.", 422);
                }
            }
            $limit = isset($query['limit']) && ctype_digit($query['limit'])
                ? max(1, min((int) $query['limit'], self::MAX_LIMIT))
                : self::DEFAULT_LIMIT;

            $repo = $this->messaging->conversations();
            $rows = $repo->list($tenantId, $filters, $after, $limit + 1);
            $next = null;
            if (count($rows) > $limit) {
                $rows = array_slice($rows, 0, $limit);
                $last = $rows[$limit - 1];
                $next = self::encodeCursor((string) $last['last_message_at'], (int) $last['id']);
            }

            return Response::json([
                'data' => array_map([MessagesPresenter::class, 'conversation'], $rows),
                'next_cursor' => $next,
                'total' => $repo->count($tenantId, $filters),
                'counts' => $repo->countsByStatus($tenantId),
            ]);
        });
    }

    /** @param array<string, string> $params */
    public function show(Request $request, array $params = []): Response
    {
        return $this->guard($request, function (int $tenantId) use ($params): Response {
            $conversation = $this->messaging->conversations()->find($tenantId, (int) ($params['id'] ?? 0));
            if ($conversation === null) {
                return Response::error('Conversation not found', 404);
            }
            $messages = $this->messaging->messages()->forConversation($tenantId, (int) $conversation['id'], null, MessagingService::pageSize());

            return Response::json(['data' => MessagesPresenter::conversation($conversation) + [
                'messages' => array_map([MessagesPresenter::class, 'message'], $messages),
            ]]);
        });
    }

    /** Start a conversation by writing to an address (email by default). */
    /** @param array<string, string> $params */
    public function start(Request $request, array $params = []): Response
    {
        return $this->guard($request, function (int $tenantId, int $profileId) use ($request): Response {
            $body = JsonBody::parsed($request);
            $to = trim((string) ($body['to'] ?? ''));
            $channel = (string) ($body['channel'] ?? 'email');
            $message = $this->messaging->send(
                $tenantId,
                Recipient::address($to, isset($body['name']) ? (string) $body['name'] : null),
                (string) ($body['body'] ?? ''),
                new SendOptions(
                    channel: $channel,
                    subject: isset($body['subject']) ? mb_substr(trim((string) $body['subject']), 0, 255) : null,
                    source: $request->isAgent() ? 'agent.reply' : 'staff.reply',
                    authorProfileId: $profileId,
                    byAgent: $request->isAgent(),
                    newConversation: true,
                )
            );

            return Response::json(['data' => MessagesPresenter::message($message)], 201);
        });
    }

    /** @param array<string, string> $params */
    public function reply(Request $request, array $params = []): Response
    {
        return $this->guard($request, function (int $tenantId, int $profileId) use ($request, $params): Response {
            $body = JsonBody::parsed($request);
            $message = $this->messaging->reply(
                $tenantId,
                (int) ($params['id'] ?? 0),
                $profileId,
                (string) ($body['body'] ?? ''),
                $request->isAgent()
            );

            return Response::json(['data' => MessagesPresenter::message($message)], 201);
        });
    }

    /** @param array<string, string> $params */
    public function note(Request $request, array $params = []): Response
    {
        return $this->guard($request, function (int $tenantId, int $profileId) use ($request, $params): Response {
            $body = JsonBody::parsed($request);
            $message = $this->messaging->note($tenantId, (int) ($params['id'] ?? 0), $profileId, (string) ($body['body'] ?? ''), $request->isAgent());

            return Response::json(['data' => MessagesPresenter::message($message)], 201);
        });
    }

    /** Change the status and/or the assignee. */
    /** @param array<string, string> $params */
    public function update(Request $request, array $params = []): Response
    {
        return $this->guard($request, function (int $tenantId) use ($request, $params): Response {
            $id = (int) ($params['id'] ?? 0);
            $repo = $this->messaging->conversations();
            if ($repo->find($tenantId, $id) === null) {
                return Response::error('Conversation not found', 404);
            }
            $body = JsonBody::parsed($request);

            if (array_key_exists('status', $body)) {
                $status = (string) $body['status'];
                if (!in_array($status, ConversationRepository::STATUSES, true)) {
                    return Response::error('Status must be open, pending or closed', 422);
                }
                $repo->setStatus($tenantId, $id, $status);
            }
            if (array_key_exists('assigned_profile_id', $body)) {
                $assignee = $body['assigned_profile_id'];
                if ($assignee !== null && (!is_int($assignee) || !$repo->isStaff($tenantId, $assignee))) {
                    return Response::error('Conversations can only be assigned to staff of this workspace', 422);
                }
                $repo->assign($tenantId, $id, $assignee);
            }

            return Response::json(['data' => MessagesPresenter::conversation((array) $repo->find($tenantId, $id))]);
        });
    }

    /** Stop a message that has not left yet, such as an agent's held reply. */
    /** @param array<string, string> $params */
    public function cancel(Request $request, array $params = []): Response
    {
        return $this->guard($request, function (int $tenantId) use ($params): Response {
            $id = (int) ($params['id'] ?? 0);
            if (!$this->messaging->cancel($tenantId, $id)) {
                return Response::error('This message has already been sent or does not exist', 409);
            }

            return Response::json(['data' => MessagesPresenter::message((array) $this->messaging->messages()->find($tenantId, $id))]);
        });
    }

    /** @param array<string, string> $params */
    public function channels(Request $request, array $params = []): Response
    {
        return $this->guard($request, fn (int $tenantId): Response => Response::json([
            'data' => $this->messaging->channels()->catalogue($tenantId),
        ]));
    }

    /** @param array<string, string> $params */
    public function getSettings(Request $request, array $params = []): Response
    {
        return $this->guard($request, fn (int $tenantId): Response => Response::json(['data' => $this->settings->get($tenantId)]));
    }

    /** @param array<string, string> $params */
    public function putSettings(Request $request, array $params = []): Response
    {
        return $this->guard($request, function (int $tenantId) use ($request): Response {
            $body = JsonBody::parsed($request);
            $current = $this->settings->get($tenantId);

            $enabled = array_key_exists('webchat_enabled', $body) ? $body['webchat_enabled'] : $current['webchat_enabled'];
            if (!is_bool($enabled)) {
                return Response::error('webchat_enabled must be true or false', 422);
            }
            $greeting = array_key_exists('webchat_greeting', $body) ? $body['webchat_greeting'] : $current['webchat_greeting'];
            if ($greeting !== null && (!is_string($greeting) || mb_strlen($greeting) > MessagingSettings::MAX_GREETING)) {
                return Response::error('The greeting must be text of at most 500 characters', 422);
            }
            $address = array_key_exists('inbound_email_address', $body) ? $body['inbound_email_address'] : $current['inbound_email_address'];
            if ($address === '') {
                $address = null;
            }
            if ($address !== null && (!is_string($address) || filter_var($address, FILTER_VALIDATE_EMAIL) === false)) {
                return Response::error('The inbound address must be an email address', 422);
            }

            $this->settings->save($tenantId, $enabled, $greeting !== null ? trim($greeting) : null, $address);

            return Response::json(['data' => $this->settings->get($tenantId)]);
        });
    }

    /** Make a new inbound email secret. It is shown once. */
    /** @param array<string, string> $params */
    public function rotateInboundSecret(Request $request, array $params = []): Response
    {
        return $this->guard($request, fn (int $tenantId): Response => Response::json(['data' => [
            'secret' => $this->settings->rotateInboundSecret($tenantId),
            'header' => 'X-Whity-Signature',
            'algorithm' => 'sha256=<hex HMAC-SHA256 of the raw request body>',
        ]], 201));
    }

    /**
     * Runs `$fn(tenantId, profileId)` with the workspace and caller bound, and
     * turns a {@see MessagingException} into a 422 in plain words.
     *
     * @param callable(int, int): Response $fn
     */
    private function guard(Request $request, callable $fn): Response
    {
        $tenantId = TenantContext::getTenantId();
        $profileId = self::profileId($request);
        if ($tenantId === null || $profileId === null) {
            return Response::error('Tenant context is required', 403);
        }

        try {
            return $fn($tenantId, $profileId);
        } catch (MessagingException $e) {
            return Response::error($e->getMessage(), 422);
        } catch (\Throwable $e) {
            error_log('[MessagesApiHandler] request failed: ' . $e->getMessage());

            return Response::error('Something went wrong with Messages. Please try again.', 500);
        }
    }

    private static function profileId(Request $request): ?int
    {
        $user = $request->user;
        $id = is_object($user) ? ($user->profile_id ?? null) : null;

        return is_int($id) && $id > 0 ? $id : null;
    }

    /** @return array<string, string> */
    public static function query(Request $request): array
    {
        $query = [];
        foreach ($_GET as $k => $v) {
            if (is_string($k) && is_string($v)) {
                $query[$k] = $v;
            }
        }
        $qs = parse_url($request->getPath(), PHP_URL_QUERY);
        if (is_string($qs) && $qs !== '') {
            parse_str($qs, $parsed);
            foreach ($parsed as $k => $v) {
                if (is_string($k) && is_string($v)) {
                    $query[$k] = $v;
                }
            }
        }

        return $query;
    }

    private static function encodeCursor(string $at, int $id): string
    {
        return rtrim(strtr(base64_encode($at . '|' . $id), '+/', '-_'), '=');
    }

    /** @return array{0: string, 1: int}|null */
    private static function decodeCursor(string $cursor): ?array
    {
        $raw = base64_decode(strtr($cursor, '-_', '+/'), true);
        if ($raw === false || preg_match('/^(\d{4}-\d{2}-\d{2}[ T][\d:.]+[^|]*)\|(\d{1,18})$/', $raw, $m) !== 1) {
            return null;
        }

        return [$m[1], (int) $m[2]];
    }
}
