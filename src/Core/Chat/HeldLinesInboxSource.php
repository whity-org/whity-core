<?php

declare(strict_types=1);

namespace Whity\Core\Chat;

use PDO;
use Whity\Sdk\Inbox\InboxCursor;
use Whity\Sdk\Inbox\InboxItem;
use Whity\Sdk\Inbox\KeysetInboxSource;

/**
 * An agent's chat lines waiting for the person it works for (CH.1, "Ask
 * first" on posting), in the Inbox's Waiting for me (merge map C3,
 * WHIT-1122).
 *
 * A held line is decided in the chat, where it reads in context
 * ({@see ChatService::decideHeld()}). Until now it showed only there, so a
 * person away from the chat never saw it waiting. This lists the lines held
 * for the person, newest first, so they wait beside documents, proposals and
 * customer chats, and each row opens its chat. With done items included, it
 * also lists the lines they decided.
 */
final class HeldLinesInboxSource implements KeysetInboxSource
{
    public const KEY = 'chat_held_lines';

    /** Most lines read at once; held lines are few, so this is a ceiling, not a page. */
    private const SCAN = 500;

    public function __construct(private readonly PDO $db)
    {
    }

    public function label(): string
    {
        return 'Agents waiting in chats';
    }

    public function list(int $tenantId, int $profileId, bool $openOnly, int $limit, int $offset): array
    {
        return array_slice($this->items($tenantId, $profileId, $openOnly), $offset, $limit);
    }

    public function listAfter(int $tenantId, int $profileId, bool $openOnly, ?InboxCursor $after, int $limit): array
    {
        $items = $this->items($tenantId, $profileId, $openOnly);
        if ($after !== null && $after->source === self::KEY) {
            $items = array_values(array_filter(
                $items,
                static fn (InboxItem $item): bool => [$item->timestamp, (int) $item->id] < [$after->timestamp, (int) $after->id],
            ));
        }

        return array_slice($items, 0, $limit);
    }

    public function count(int $tenantId, int $profileId, bool $openOnly): int
    {
        return count($this->items($tenantId, $profileId, $openOnly));
    }

    /** @return list<InboxItem> newest first */
    private function items(int $tenantId, int $profileId, bool $openOnly): array
    {
        // chat_meta is JSONB on Postgres and text elsewhere; read it as text and
        // decode here rather than use either engine's JSON operators.
        $markers = $openOnly ? "CAST(m.chat_meta AS TEXT) LIKE '%\"held\"%'" : "(CAST(m.chat_meta AS TEXT) LIKE '%\"held\"%' OR CAST(m.chat_meta AS TEXT) LIKE '%\"decided\"%')";
        $stmt = $this->db->prepare(
            "SELECT m.id, m.conversation_id, m.body_text, m.status, m.chat_meta, m.created_at, c.subject
               FROM messages m
               JOIN conversations c ON c.id = m.conversation_id AND c.tenant_id = m.tenant_id
              WHERE m.tenant_id = :tid AND m.by_agent = :agent AND c.kind = 'team' AND {$markers}
              ORDER BY m.created_at DESC, m.id DESC
              LIMIT " . self::SCAN
        );
        $stmt->bindValue(':tid', $tenantId, PDO::PARAM_INT);
        $stmt->bindValue(':agent', true, PDO::PARAM_BOOL);
        $stmt->execute();

        $items = [];
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
            $meta = is_string($row['chat_meta']) ? json_decode($row['chat_meta'], true) : $row['chat_meta'];
            if (!is_array($meta)) {
                continue;
            }
            $held = is_array($meta['held'] ?? null) ? $meta['held'] : null;
            $decided = is_array($meta['decided'] ?? null) ? $meta['decided'] : null;
            $waitingForMe = $held !== null && (int) ($held['by']['id'] ?? 0) === $profileId && (string) $row['status'] === 'noted';
            $decidedByMe = !$openOnly && $decided !== null && (int) ($decided['by']['id'] ?? 0) === $profileId;
            if (!$waitingForMe && !$decidedByMe) {
                continue;
            }
            $agent = is_array($meta['agent'] ?? null) ? (string) ($meta['agent']['name'] ?? '') : '';
            $items[] = new InboxItem(
                id: (string) $row['id'],
                title: $agent !== '' ? $agent : 'An agent',
                subtitle: mb_substr((string) $row['body_text'], 0, 140),
                timestamp: (string) $row['created_at'],
                status: $waitingForMe ? 'waiting' : (($decided['approved'] ?? false) === true ? 'approved' : 'declined'),
                resourceType: 'chat',
                resourceId: (string) $row['conversation_id'],
                meta: ['message_id' => (int) $row['id'], 'chat' => $row['subject'] !== null ? (string) $row['subject'] : null],
                sender: $agent !== '' ? ['kind' => 'agent', 'name' => $agent] : null,
            );
        }

        return $items;
    }
}
