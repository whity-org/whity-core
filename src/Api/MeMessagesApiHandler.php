<?php

declare(strict_types=1);

namespace Whity\Api;

use Whity\Core\Messaging\MessagesPresenter;
use Whity\Core\Messaging\MessagingException;
use Whity\Core\Messaging\MessagingService;
use Whity\Core\Messaging\WebChat\WebChatChannel;
use Whity\Core\Request;
use Whity\Core\Response;
use Whity\Core\Tenant\TenantContext;
use Whity\Http\JsonBody;
use Whity\Sdk\Messaging\InboundMessage;

/**
 * A signed-in customer's own conversations with the workspace (ADR 0016 §5):
 * `/api/me/messages`.
 *
 * Session-gated and unpermissioned, like the other `/api/me` routes: the
 * conversation row names its customer, so ownership is the whole check. Only
 * the customer view is shown: no notes, nothing held or failed. A new chat
 * goes through the web chat channel, so it needs web chat turned on.
 */
final class MeMessagesApiHandler
{
    private const LIST_LIMIT = 50;

    public function __construct(private readonly MessagingService $messaging)
    {
    }

    /** @param array<string, string> $params */
    public function list(Request $request, array $params = []): Response
    {
        return $this->guard($request, function (int $tenantId, int $profileId): Response {
            $rows = $this->messaging->conversations()->listForCustomer($tenantId, $profileId, self::LIST_LIMIT);

            return Response::json(['data' => array_map(static fn (array $row): array => [
                'id' => (int) $row['id'],
                'channel' => (string) $row['channel'],
                'subject' => $row['subject'] !== null ? (string) $row['subject'] : null,
                'status' => (string) $row['status'],
                'last_message_at' => (string) $row['last_message_at'],
            ], $rows)]);
        });
    }

    /** @param array<string, string> $params */
    public function show(Request $request, array $params = []): Response
    {
        return $this->guard($request, function (int $tenantId, int $profileId) use ($request, $params): Response {
            $conversation = $this->owned($tenantId, $profileId, (int) ($params['id'] ?? 0));
            if ($conversation === null) {
                return Response::error('Conversation not found', 404);
            }
            $after = MessagesApiHandler::query($request)['after'] ?? '';
            $messages = $this->messaging->messages()->forConversation(
                $tenantId,
                (int) $conversation['id'],
                ctype_digit($after) ? (int) $after : null,
                MessagingService::pageSize(),
                true
            );

            return Response::json(['data' => [
                'id' => (int) $conversation['id'],
                'channel' => (string) $conversation['channel'],
                'subject' => $conversation['subject'] !== null ? (string) $conversation['subject'] : null,
                'status' => (string) $conversation['status'],
                'messages' => array_map([MessagesPresenter::class, 'customerMessage'], $messages),
            ]]);
        });
    }

    /** Write to the workspace: continues the customer's open chat, or starts one. */
    /** @param array<string, string> $params */
    public function start(Request $request, array $params = []): Response
    {
        return $this->guard($request, function (int $tenantId, int $profileId) use ($request): Response {
            if (!$this->messaging->channels()->get(WebChatChannel::KEY)?->isEnabled($tenantId)) {
                return Response::error('This workspace does not take messages here yet.', 404);
            }
            $body = JsonBody::parsed($request);
            $message = $this->messaging->ingest(
                $tenantId,
                WebChatChannel::KEY,
                new InboundMessage(
                    from: 'profile:' . $profileId,
                    fromName: $this->name($request),
                    body: (string) ($body['message'] ?? ''),
                ),
                null,
                $profileId
            );

            return Response::json(['data' => $message !== null ? [
                'conversation_id' => (int) $message['conversation_id'],
                'message' => MessagesPresenter::customerMessage($message),
            ] : null], 201);
        });
    }

    /** @param array<string, string> $params */
    public function send(Request $request, array $params = []): Response
    {
        return $this->guard($request, function (int $tenantId, int $profileId) use ($request, $params): Response {
            $conversation = $this->owned($tenantId, $profileId, (int) ($params['id'] ?? 0));
            if ($conversation === null) {
                return Response::error('Conversation not found', 404);
            }
            $body = JsonBody::parsed($request);
            $message = $this->messaging->ingest(
                $tenantId,
                (string) $conversation['channel'],
                new InboundMessage(
                    from: (string) $conversation['contact_address'],
                    fromName: $this->name($request),
                    body: (string) ($body['message'] ?? ''),
                ),
                (int) $conversation['id'],
                $profileId
            );

            return Response::json(['data' => $message !== null ? MessagesPresenter::customerMessage($message) : null], 201);
        });
    }

    /** @return array<string, mixed>|null */
    private function owned(int $tenantId, int $profileId, int $id): ?array
    {
        $conversation = $this->messaging->conversations()->find($tenantId, $id);
        if ($conversation === null || (int) ($conversation['customer_profile_id'] ?? 0) !== $profileId) {
            return null;
        }

        return $conversation;
    }

    private function name(Request $request): ?string
    {
        $user = $request->user;
        $name = is_object($user) ? ($user->display_name ?? $user->name ?? null) : null;

        return is_string($name) && $name !== '' ? $name : null;
    }

    /** @param callable(int, int): Response $fn */
    private function guard(Request $request, callable $fn): Response
    {
        $tenantId = TenantContext::getTenantId();
        $user = $request->user;
        $profileId = is_object($user) ? ($user->profile_id ?? null) : null;
        if ($tenantId === null || !is_int($profileId) || $profileId <= 0) {
            return Response::error('Unauthenticated', 401);
        }

        try {
            return $fn($tenantId, $profileId);
        } catch (MessagingException $e) {
            return Response::error($e->getMessage(), 422);
        } catch (\Throwable $e) {
            error_log('[MeMessagesApiHandler] request failed: ' . $e->getMessage());

            return Response::error('Your message could not be sent. Please try again.', 500);
        }
    }
}
