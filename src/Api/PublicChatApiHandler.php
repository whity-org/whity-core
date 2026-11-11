<?php

declare(strict_types=1);

namespace Whity\Api;

use PDO;
use Whity\Core\Branding\HostResolver;
use Whity\Core\Branding\TenantHostRepository;
use Whity\Core\Messaging\MessagesPresenter;
use Whity\Core\Messaging\MessagingException;
use Whity\Core\Messaging\MessagingService;
use Whity\Core\Messaging\MessagingSettings;
use Whity\Core\Messaging\WebChat\WebChatChannel;
use Whity\Core\Plugin\PublicForm\BotCheck;
use Whity\Core\RateLimit\ClientIp;
use Whity\Core\Request;
use Whity\Core\Response;
use Whity\Core\Store\SharedStoreInterface;
use Whity\Http\JsonBody;
use Whity\Sdk\Messaging\InboundMessage;

/**
 * Web chat for visitors without an account (ADR 0016 §5):
 * `/api/public/chat/{workspace}/…`.
 *
 * WHICH WORKSPACE. `{workspace}` is the slug. When the request's host names a
 * workspace too (a custom domain), the two must agree. An unknown workspace, a
 * workspace with web chat off, and the system workspace all answer the same
 * 404, so this cannot be used to list workspaces.
 *
 * WHO IS TALKING. Starting a chat returns a random token once; the browser
 * keeps it and sends it as `Authorization: Bearer`. Only its SHA-256 is
 * stored. A token reaches exactly one conversation, and only its customer view:
 * no notes, nothing held or failed.
 *
 * ABUSE. Before anything is written: per-address and per-workspace windows,
 * a hidden `website` field that people never fill, a body cap, the instance's
 * bot check (Turnstile) on starting a chat when one is set up, and at most
 * {@see MAX_OPEN_PER_IP} chats started from one address a day.
 */
final class PublicChatApiHandler
{
    private const SYSTEM_TENANT_ID = 0;
    private const NOT_FOUND = 'Chat is not available here';

    private const WINDOW_START = 3600;
    private const START_PER_IP = 5;
    private const WINDOW_SEND = 600;
    private const SEND_PER_TOKEN = 30;
    private const WINDOW_WORKSPACE = 3600;
    private const PER_WORKSPACE = 600;
    private const WINDOW_POLL = 3600;
    private const POLL_PER_TOKEN = 1500;
    private const MAX_OPEN_PER_IP = 3;
    private const MAX_NAME = 120;

    public function __construct(
        private readonly PDO $db,
        private readonly MessagingService $messaging,
        private readonly MessagingSettings $settings,
        private readonly TenantHostRepository $tenants,
        private readonly HostResolver $hosts,
        private readonly BotCheck $bot,
        private readonly SharedStoreInterface $store,
        private readonly string $ipPepper,
    ) {
    }

    /** @param array<string, string> $params */
    public function info(Request $request, array $params = []): Response
    {
        $workspace = $this->workspace($request, $params);
        if ($workspace === null) {
            return Response::error(self::NOT_FOUND, 404);
        }

        return Response::json(['data' => [
            'workspace_name' => $workspace['name'],
            'greeting' => $workspace['greeting'],
            'max_length' => WebChatChannel::MAX_BODY,
            'bot_check' => $this->bot->isReady()
                ? ['provider' => $this->bot->provider(), 'site_key' => $this->bot->siteKey()]
                : null,
        ]]);
    }

    /** @param array<string, string> $params */
    public function start(Request $request, array $params = []): Response
    {
        $ip = ClientIp::fromRequest($request);
        if (($limited = $this->limit('chat:start:ip:', $ip, self::START_PER_IP, self::WINDOW_START)) !== null) {
            return $limited;
        }
        $workspace = $this->workspace($request, $params);
        if ($workspace === null) {
            return Response::error(self::NOT_FOUND, 404);
        }
        $tenantId = $workspace['id'];
        if (($limited = $this->limit('chat:ws:', (string) $tenantId, self::PER_WORKSPACE, self::WINDOW_WORKSPACE)) !== null) {
            return $limited;
        }

        $body = JsonBody::parsed($request);
        if (self::isBot($body)) {
            // Answer like a success so the bot learns nothing; nothing is written.
            return Response::json(['data' => ['token' => bin2hex(random_bytes(32)), 'messages' => []]], 201);
        }

        if ($this->bot->isReady()) {
            $verdict = $this->bot->verify((string) ($body['bot_token'] ?? ''), $ip);
            if ($verdict === BotCheck::UNREACHABLE) {
                return Response::error('The bot check could not be reached. Please try again.', 503);
            }
            if ($verdict !== BotCheck::PASSED) {
                return Response::error('Please complete the check and try again.', 422);
            }
        }

        $ipHash = $ip !== null ? hash_hmac('sha256', $tenantId . '|' . $ip, $this->ipPepper) : null;
        if ($ipHash !== null && $this->openFromAddress($tenantId, $ipHash) >= self::MAX_OPEN_PER_IP) {
            return Response::error('Too many chats from this address today. Please try again tomorrow.', 429);
        }

        $name = trim((string) ($body['name'] ?? ''));
        $name = $name !== '' ? mb_substr($name, 0, self::MAX_NAME) : null;
        $email = trim((string) ($body['email'] ?? ''));
        if ($email !== '' && filter_var($email, FILTER_VALIDATE_EMAIL) === false) {
            return Response::error('The email address is not valid.', 422);
        }

        $token = rtrim(strtr(base64_encode(random_bytes(32)), '+/', '-_'), '=');
        $visitorAddress = 'visitor:' . substr(hash('sha256', $token), 0, 24);

        // Joins a transaction the caller already holds rather than nesting one.
        $own = !$this->db->inTransaction();
        try {
            if ($own) {
                $this->db->beginTransaction();
            }
            $message = $this->messaging->ingest(
                $tenantId,
                WebChatChannel::KEY,
                new InboundMessage(from: $visitorAddress, fromName: $name ?? ($email !== '' ? $email : null), body: (string) ($body['message'] ?? ''))
            );
            if ($message === null) {
                throw new MessagingException('The message could not be filed.');
            }
            $this->db->prepare(
                'INSERT INTO webchat_visitors (tenant_id, conversation_id, token_hash, display_name, email, ip_hash, created_at, last_seen_at)
                 VALUES (:tid, :cid, :hash, :name, :email, :ip, NOW(), NOW())'
            )->execute([
                ':tid' => $tenantId,
                ':cid' => (int) $message['conversation_id'],
                ':hash' => hash('sha256', $token),
                ':name' => $name,
                ':email' => $email !== '' ? strtolower($email) : null,
                ':ip' => $ipHash,
            ]);
            if ($own) {
                $this->db->commit();
            }
        } catch (MessagingException $e) {
            $this->rollBack($own);

            return Response::error($e->getMessage(), 422);
        } catch (\Throwable $e) {
            $this->rollBack($own);
            error_log('[PublicChat] start failed: ' . $e->getMessage());

            return Response::error('The chat could not be started. Please try again.', 500);
        }

        return Response::json(['data' => [
            'token' => $token,
            'messages' => [MessagesPresenter::customerMessage($message)],
        ]], 201);
    }

    /** @param array<string, string> $params */
    public function send(Request $request, array $params = []): Response
    {
        $workspace = $this->workspace($request, $params);
        if ($workspace === null) {
            return Response::error(self::NOT_FOUND, 404);
        }
        $visitor = $this->visitor($request, $workspace['id']);
        if ($visitor === null) {
            return Response::error('This chat has ended. Start a new one.', 401);
        }
        if (($limited = $this->limit('chat:send:', (string) $visitor['id'], self::SEND_PER_TOKEN, self::WINDOW_SEND)) !== null) {
            return $limited;
        }
        if (($limited = $this->limit('chat:ws:', (string) $workspace['id'], self::PER_WORKSPACE, self::WINDOW_WORKSPACE)) !== null) {
            return $limited;
        }

        $body = JsonBody::parsed($request);
        if (self::isBot($body)) {
            return Response::json(['data' => null], 201);
        }

        try {
            $conversation = $this->messaging->conversations()->find($workspace['id'], (int) $visitor['conversation_id']);
            if ($conversation === null) {
                return Response::error('This chat has ended. Start a new one.', 401);
            }
            $message = $this->messaging->ingest(
                $workspace['id'],
                WebChatChannel::KEY,
                new InboundMessage(
                    from: (string) $conversation['contact_address'],
                    fromName: $visitor['display_name'],
                    body: (string) ($body['message'] ?? ''),
                ),
                (int) $visitor['conversation_id']
            );
        } catch (MessagingException $e) {
            return Response::error($e->getMessage(), 422);
        } catch (\Throwable $e) {
            error_log('[PublicChat] send failed: ' . $e->getMessage());

            return Response::error('The message could not be sent. Please try again.', 500);
        }

        return Response::json(['data' => $message !== null ? MessagesPresenter::customerMessage($message) : null], 201);
    }

    /** @param array<string, string> $params */
    public function poll(Request $request, array $params = []): Response
    {
        $workspace = $this->workspace($request, $params);
        if ($workspace === null) {
            return Response::error(self::NOT_FOUND, 404);
        }
        $visitor = $this->visitor($request, $workspace['id']);
        if ($visitor === null) {
            return Response::error('This chat has ended. Start a new one.', 401);
        }
        if (($limited = $this->limit('chat:poll:', (string) $visitor['id'], self::POLL_PER_TOKEN, self::WINDOW_POLL)) !== null) {
            return $limited;
        }

        $after = MessagesApiHandler::query($request)['after'] ?? '';
        $messages = $this->messaging->messages()->forConversation(
            $workspace['id'],
            (int) $visitor['conversation_id'],
            ctype_digit($after) ? (int) $after : null,
            MessagingService::pageSize(),
            true
        );
        $this->db->prepare('UPDATE webchat_visitors SET last_seen_at = NOW() WHERE tenant_id = :tid AND id = :id')
            ->execute([':tid' => $workspace['id'], ':id' => (int) $visitor['id']]);

        return Response::json(['data' => array_map([MessagesPresenter::class, 'customerMessage'], $messages)]);
    }

    /**
     * @return array{id: int, name: string, greeting: ?string}|null
     * @param array<string, string> $params
     */
    private function workspace(Request $request, array $params): ?array
    {
        $slug = strtolower(trim((string) ($params['workspace'] ?? '')));
        if ($slug === '' || strlen($slug) > 255 || preg_match('/^[a-z0-9][a-z0-9-]*$/', $slug) !== 1) {
            return null;
        }

        try {
            $tenantId = $this->tenants->findIdBySlug($slug);
            if ($tenantId === null || $tenantId === self::SYSTEM_TENANT_ID) {
                return null;
            }
            $host = $request->getHeader('X-Forwarded-Host') ?? $request->getHeader('Host') ?? '';
            $hostTenant = $this->hosts->resolveTenantIdByHost($host);
            if ($hostTenant !== null && $hostTenant !== $tenantId) {
                return null;
            }
            $settings = $this->settings->get($tenantId);
            if (!$settings['webchat_enabled']) {
                return null;
            }
            $name = $this->tenants->findNameById($tenantId);
        } catch (\Throwable $e) {
            error_log('[PublicChat] workspace lookup failed: ' . $e->getMessage());

            return null;
        }

        return ['id' => $tenantId, 'name' => $name ?? $slug, 'greeting' => $settings['webchat_greeting']];
    }

    /** @return array{id: int, conversation_id: int, display_name: ?string}|null */
    private function visitor(Request $request, int $tenantId): ?array
    {
        $header = (string) ($request->getHeader('Authorization') ?? '');
        if (stripos($header, 'Bearer ') !== 0) {
            return null;
        }
        $token = trim(substr($header, 7));
        if ($token === '' || strlen($token) > 128) {
            return null;
        }
        $stmt = $this->db->prepare(
            'SELECT id, conversation_id, display_name FROM webchat_visitors WHERE tenant_id = :tid AND token_hash = :hash'
        );
        $stmt->execute([':tid' => $tenantId, ':hash' => hash('sha256', $token)]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        if ($row === false) {
            return null;
        }

        return [
            'id' => (int) $row['id'],
            'conversation_id' => (int) $row['conversation_id'],
            'display_name' => $row['display_name'] !== null ? (string) $row['display_name'] : null,
        ];
    }

    private function openFromAddress(int $tenantId, string $ipHash): int
    {
        $stmt = $this->db->prepare(
            'SELECT COUNT(*) FROM webchat_visitors WHERE tenant_id = :tid AND ip_hash = :ip AND created_at > :since'
        );
        $stmt->execute([':tid' => $tenantId, ':ip' => $ipHash, ':since' => gmdate('Y-m-d H:i:s', time() - 86400)]);

        return (int) $stmt->fetchColumn();
    }

    /** @param array<string, mixed> $body */
    private static function isBot(array $body): bool
    {
        return isset($body['website']) && trim((string) $body['website']) !== '';
    }

    private function limit(string $prefix, ?string $subject, int $max, int $window): ?Response
    {
        if ($subject === null || $subject === '') {
            return null;
        }
        $key = $prefix . $subject;
        if ($this->store->count($key) >= $max) {
            return Response::error('Too many messages. Please wait a moment and try again.', 429)
                ->withHeaders(['Retry-After' => (string) max($this->store->ttl($key), 1)]);
        }
        $this->store->increment($key, $window);

        return null;
    }

    private function rollBack(bool $own): void
    {
        if ($own && $this->db->inTransaction()) {
            $this->db->rollBack();
        }
    }
}
