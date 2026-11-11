<?php

declare(strict_types=1);

namespace Whity\Core\Messaging;

use Whity\Core\Db\DbBool;

/**
 * The wire shape of conversations and messages. People appear as id and name,
 * never as a bare id (design register A.3).
 */
final class MessagesPresenter
{
    private function __construct()
    {
    }

    /**
     * @param array<string, mixed> $row
     *
     * @return array<string, mixed>
     */
    public static function conversation(array $row): array
    {
        return [
            'id' => (int) $row['id'],
            'channel' => (string) $row['channel'],
            'subject' => $row['subject'] !== null ? (string) $row['subject'] : null,
            'status' => (string) $row['status'],
            'contact' => [
                'name' => $row['contact_name'] !== null ? (string) $row['contact_name'] : null,
                'address' => (string) $row['contact_address'],
            ],
            'customer' => $row['customer_profile_id'] !== null
                ? ['id' => (int) $row['customer_profile_id'], 'name' => (string) ($row['customer_name'] ?? '')]
                : null,
            'assignee' => $row['assigned_profile_id'] !== null
                ? ['id' => (int) $row['assigned_profile_id'], 'name' => (string) ($row['assigned_name'] ?? '')]
                : null,
            'last_message_at' => (string) $row['last_message_at'],
            'last_inbound_at' => $row['last_inbound_at'] !== null ? (string) $row['last_inbound_at'] : null,
            'preview' => isset($row['last_body']) ? mb_substr((string) $row['last_body'], 0, 160) : null,
            'created_at' => (string) $row['created_at'],
            'closed_at' => $row['closed_at'] !== null ? (string) $row['closed_at'] : null,
        ];
    }

    /**
     * @param array<string, mixed> $row
     *
     * @return array<string, mixed>
     */
    public static function message(array $row): array
    {
        return [
            'id' => (int) $row['id'],
            'conversation_id' => (int) $row['conversation_id'],
            'kind' => (string) $row['kind'],
            'body' => (string) $row['body_text'],
            'has_html' => $row['body_html'] !== null && $row['body_html'] !== '',
            'author' => $row['author_profile_id'] !== null
                ? ['id' => (int) $row['author_profile_id'], 'name' => (string) ($row['author_name'] ?? '')]
                : null,
            'by_agent' => DbBool::of($row['by_agent']),
            'source' => $row['source'] !== null ? (string) $row['source'] : null,
            'status' => (string) $row['status'],
            'status_detail' => $row['status_detail'] !== null ? (string) $row['status_detail'] : null,
            'attachments_not_shown' => (int) $row['attachments_not_shown'],
            'send_after' => $row['send_after'] !== null ? (string) $row['send_after'] : null,
            'sent_at' => $row['sent_at'] !== null ? (string) $row['sent_at'] : null,
            'created_at' => (string) $row['created_at'],
        ];
    }

    /**
     * What a customer or visitor sees of a message: no staff names beyond the
     * workspace, no notes, no internal status.
     *
     * @param array<string, mixed> $row
     *
     * @return array<string, mixed>
     */
    public static function customerMessage(array $row): array
    {
        return [
            'id' => (int) $row['id'],
            'from' => $row['kind'] === MessageRepository::KIND_INBOUND ? 'you' : 'workspace',
            'author_name' => $row['kind'] === MessageRepository::KIND_OUTBOUND && !DbBool::of($row['by_agent']) && $row['author_name'] !== null
                ? (string) $row['author_name']
                : null,
            'body' => (string) $row['body_text'],
            'created_at' => (string) $row['created_at'],
        ];
    }
}
