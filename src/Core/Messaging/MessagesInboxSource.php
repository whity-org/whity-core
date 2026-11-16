<?php

declare(strict_types=1);

namespace Whity\Core\Messaging;

use Whity\Sdk\Inbox\InboxCursor;
use Whity\Sdk\Inbox\InboxItem;
use Whity\Sdk\Inbox\KeysetInboxSource;

/**
 * Open conversations assigned to the person, in their inbox and Waiting for
 * you (ADR 0016 §6). Newest activity first; seeks by position.
 */
final class MessagesInboxSource implements KeysetInboxSource
{
    public const KEY = 'messages';

    public function __construct(private readonly ConversationRepository $conversations)
    {
    }

    public function label(): string
    {
        return 'Messages';
    }

    public function list(int $tenantId, int $profileId, bool $openOnly, int $limit, int $offset): array
    {
        // Offset reads walk the keyset from the start; inbox pages are small.
        $items = $this->listAfter($tenantId, $profileId, $openOnly, null, $offset + $limit);

        return array_slice($items, $offset, $limit);
    }

    public function listAfter(int $tenantId, int $profileId, bool $openOnly, ?InboxCursor $after, int $limit): array
    {
        $keyset = null;
        if ($after !== null && $after->source === self::KEY && ctype_digit($after->id)) {
            $keyset = [$after->timestamp, (int) $after->id];
        } elseif ($after !== null) {
            // A cursor from another source: read from the start and let the
            // aggregate keep what comes after it.
            $limit = min(500, $limit * 4);
        }

        $rows = $this->conversations->assignedOpen($tenantId, $profileId, $openOnly, $keyset, $limit);

        return array_map(static fn (array $row): InboxItem => new InboxItem(
            id: (string) $row['id'],
            title: (string) ($row['contact_name'] ?? '') !== '' ? (string) $row['contact_name'] : (string) $row['contact_address'],
            subtitle: $row['subject'] !== null && $row['subject'] !== ''
                ? (string) $row['subject']
                : ($row['last_body'] !== null ? mb_substr((string) $row['last_body'], 0, 140) : null),
            timestamp: (string) $row['last_message_at'],
            status: (string) $row['status'],
            resourceType: 'conversation',
            resourceId: (string) $row['id'],
            meta: ['channel' => (string) $row['channel']],
        ), $rows);
    }

    public function count(int $tenantId, int $profileId, bool $openOnly): int
    {
        return $this->conversations->count($tenantId, ['assignee' => $profileId, 'status' => $openOnly ? 'open' : null]);
    }
}
