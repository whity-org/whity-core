<?php

declare(strict_types=1);

namespace Whity\Core\Chat;

use Psr\Log\LoggerInterface;
use Whity\Core\Notification\NotificationDispatcher;
use Whity\Core\Notification\Push\PushSubscriptionRepository;
use Whity\Core\Store\SharedStoreInterface;

/**
 * Team chat messages as web push (WHIT-1077).
 *
 * WHO. Every person in the chat except the author, who has a device on in the
 * workspace and isn't looking at that chat right now. Agents get nothing (they
 * read chats through MCP). Whether the push may go is the dispatcher's usual
 * gate: the workspace's push switch, then the person's choice for the kind,
 * `chat.message` or `chat.mention` (on unless turned off).
 *
 * LOOKING AT IT. While a chat is open in a focused window, the web app says so
 * every {@see self::HEARTBEAT} seconds ({@see focus()}); a person counts as
 * looking until a beat is missed. When the window loses focus
 * or the chat closes, the app says that too, and pushes start at once.
 *
 * GROUPED PER CHAT. Each push for a chat carries the same tag, so a new one
 * replaces the last on the device instead of piling up, and says how many are
 * unread: "3 new messages in #general". The per-person rate limit and its
 * digest apply as for every push.
 *
 * Push only: chat lines are already the record, so no in-app notification is
 * delivered for them. Nothing here ever stops a message being posted.
 */
final class ChatPushNotifier
{
    public const TYPE_MESSAGE = 'chat.message';
    public const TYPE_MENTION = 'chat.mention';

    /** Seconds between "I'm looking at this chat" beats from the web app. */
    public const HEARTBEAT = 20;

    private const PREVIEW = 140;

    public function __construct(
        private readonly NotificationDispatcher $dispatcher,
        private readonly ChatRepository $chats,
        private readonly PushSubscriptionRepository $devices,
        private readonly SharedStoreInterface $store,
        private readonly ?LoggerInterface $logger = null,
        private readonly ?\Closure $clock = null,
    ) {
    }

    /**
     * Record that a person is looking at a chat in a focused window, or has
     * stopped (window blurred, chat closed).
     */
    public function focus(int $tenantId, int $profileId, int $chatId, bool $focused): void
    {
        $now = $this->now();
        if (!$focused) {
            foreach ([0, 1] as $back) {
                $this->store->delete(self::focusKey($tenantId, $profileId, $chatId, $now, $back));
            }

            return;
        }
        // A key per beat: increment() keeps the first expiry, so a fresh key
        // each period is what keeps "looking" from outliving the beats.
        $this->store->increment(self::focusKey($tenantId, $profileId, $chatId, $now, 0), self::HEARTBEAT * 3);
    }

    /** Whether the person is looking at this chat now (this beat or the last). */
    public function isLooking(int $tenantId, int $profileId, int $chatId): bool
    {
        $now = $this->now();
        foreach ([0, 1] as $back) {
            if ($this->store->count(self::focusKey($tenantId, $profileId, $chatId, $now, $back)) > 0) {
                return true;
            }
        }

        return false;
    }

    /**
     * Push a posted line to the chat's other people.
     *
     * @param array<string, mixed>       $chat    the chat row
     * @param list<array<string, mixed>> $members its current members
     * @param list<array{type: string, id: int, name: string}> $mentions
     */
    public function posted(int $tenantId, array $chat, array $members, int $messageId, ?int $authorProfileId, string $authorName, string $body, array $mentions): void
    {
        $chatId = (int) $chat['id'];
        $mentioned = [];
        $everyone = false;
        foreach ($mentions as $m) {
            if ($m['type'] === 'person') {
                $mentioned[$m['id']] = true;
            }
            // UC.10: @everyone tells every person in the group.
            $everyone = $everyone || $m['type'] === 'everyone';
        }
        $group = (string) $chat['type'] !== 'direct';
        $title = trim((string) ($chat['subject'] ?? ''));
        $preview = self::preview($body);

        foreach ($members as $member) {
            $profileId = $member['profile_id'] !== null ? (int) $member['profile_id'] : 0;
            if ($profileId <= 0 || $member['agent_id'] !== null || $profileId === $authorProfileId) {
                continue;
            }
            try {
                $isMention = $everyone || isset($mentioned[$profileId]);
                if ($this->isLooking($tenantId, $profileId, $chatId)) {
                    continue;
                }
                // N4: a mention is a notice in the Inbox's Mentions tab, with or
                // without a device; push follows it to any device they have.
                if (!$isMention && $this->devices->listForProfile($tenantId, $profileId) === []) {
                    continue;
                }
                $mark = max((int) $member['last_read_message_id'], (int) $member['reads_after_message_id']);
                $unread = max(1, $this->chats->unreadLines($tenantId, $chatId, $mark, $profileId));
                // UC.8: a muted chat pushes nothing, except a mention, which reaches them through mute.
                if (!$isMention && $this->chats->isMuted($tenantId, $chatId, $profileId)) {
                    continue;
                }
                [$subject, $subjectAr] = self::subject($group, $title, $authorName, $unread, $isMention);

                $this->dispatcher->dispatch($tenantId, $profileId, $isMention ? self::TYPE_MENTION : self::TYPE_MESSAGE, [
                    'channels' => [$isMention ? 'in_app' : 'push'],
                    'subject'  => $subject,
                    'body'     => $group ? $authorName . ': ' . $preview : $preview,
                    'data'     => [
                        'subjectAr' => $subjectAr,
                        'bodyAr'    => $group ? $authorName . ': ' . $preview : $preview,
                        'url'       => '/chat?c=' . $chatId,
                        'push_tag'  => 'chat' . $chatId,
                        'chat_id'   => $chatId,
                        'message_id' => $messageId,
                    ],
                ]);
            } catch (\Throwable $e) {
                $this->logger?->warning('[chat] push for a chat line failed', ['tenant_id' => $tenantId, 'chat_id' => $chatId, 'error' => $e->getMessage()]);
            }
        }
    }

    /**
     * What the push says, in English and Arabic.
     *
     * @return array{0: string, 1: string}
     */
    public static function subject(bool $group, string $title, string $author, int $unread, bool $mention): array
    {
        $where = $title !== '' ? '#' . $title : '';
        if ($mention) {
            return $group && $where !== ''
                ? ["{$author} mentioned you in {$where}", "{$author} أشار إليك في {$where}"]
                : ["{$author} mentioned you", "{$author} أشار إليك"];
        }
        if ($unread > 1) {
            return $group && $where !== ''
                ? ["{$unread} new messages in {$where}", "{$unread} رسائل جديدة في {$where}"]
                : ["{$unread} new messages from {$author}", "{$unread} رسائل جديدة من {$author}"];
        }

        return $group && $where !== ''
            ? ["New message in {$where}", "رسالة جديدة في {$where}"]
            : ["New message from {$author}", "رسالة جديدة من {$author}"];
    }

    private static function preview(string $body): string
    {
        $line = trim((string) preg_replace('/\s+/u', ' ', $body));

        return mb_strlen($line) <= self::PREVIEW ? $line : rtrim(mb_substr($line, 0, self::PREVIEW - 1)) . '…';
    }

    private static function focusKey(int $tenantId, int $profileId, int $chatId, int $now, int $back): string
    {
        return 'chat-focus:' . $tenantId . ':' . $profileId . ':' . $chatId . ':' . (intdiv($now, self::HEARTBEAT) - $back);
    }

    private function now(): int
    {
        return $this->clock !== null ? (int) ($this->clock)() : time();
    }
}
