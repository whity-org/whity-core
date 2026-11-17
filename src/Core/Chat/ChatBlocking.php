<?php

declare(strict_types=1);

namespace Whity\Core\Chat;

use PDO;

/**
 * Blocking someone who sends unwanted messages (design 3.5, UC.12,
 * WHIT-1097).
 *
 * Admins block a customer's number or address from their chat; whatever that
 * number or address sends afterwards on that channel is dropped before it
 * reaches anyone, and the person is not told. Past messages stay. People in
 * the team can't be blocked, only muted: blocking takes a customer
 * conversation's contact, never a person.
 */
final class ChatBlocking
{
    public function __construct(private readonly PDO $db)
    {
    }

    public function isBlocked(int $tenantId, string $channel, string $address): bool
    {
        $stmt = $this->db->prepare('SELECT 1 FROM chat_blocked_senders WHERE tenant_id = :tid AND channel = :ch AND address_key = :key LIMIT 1');
        $stmt->execute([':tid' => $tenantId, ':ch' => $channel, ':key' => self::key($address)]);

        return $stmt->fetchColumn() !== false;
    }

    /**
     * Block the contact of a customer conversation.
     *
     * @return array<string, mixed> the block
     *
     * @throws ChatException
     */
    public function blockConversation(int $tenantId, int $conversationId, bool $reportSpam, int $byProfileId): array
    {
        $stmt = $this->db->prepare("SELECT channel, contact_address, contact_name FROM conversations WHERE tenant_id = :tid AND id = :id AND kind = 'customer'");
        $stmt->execute([':tid' => $tenantId, ':id' => $conversationId]);
        $c = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!is_array($c)) {
            throw ChatException::notFound();
        }
        $existing = $this->find($tenantId, (string) $c['channel'], (string) $c['contact_address']);
        if ($existing !== null) {
            return $existing;
        }
        $insert = $this->db->prepare('INSERT INTO chat_blocked_senders (tenant_id, channel, address, address_key, name, reported_spam, blocked_by_profile_id, created_at)
            VALUES (:tid, :ch, :address, :key, :name, :spam, :by, NOW())');
        $insert->bindValue(':tid', $tenantId, PDO::PARAM_INT);
        $insert->bindValue(':ch', (string) $c['channel']);
        $insert->bindValue(':address', (string) $c['contact_address']);
        $insert->bindValue(':key', self::key((string) $c['contact_address']));
        $insert->bindValue(':name', $c['contact_name'] !== null ? (string) $c['contact_name'] : null, $c['contact_name'] !== null ? PDO::PARAM_STR : PDO::PARAM_NULL);
        $insert->bindValue(':spam', $reportSpam, PDO::PARAM_BOOL);
        $insert->bindValue(':by', $byProfileId, PDO::PARAM_INT);
        $insert->execute();

        return $this->find($tenantId, (string) $c['channel'], (string) $c['contact_address']) ?? [];
    }

    /** @return array<string, mixed>|null the block on this conversation's contact */
    public function forConversation(int $tenantId, int $conversationId): ?array
    {
        $stmt = $this->db->prepare("SELECT channel, contact_address FROM conversations WHERE tenant_id = :tid AND id = :id AND kind = 'customer'");
        $stmt->execute([':tid' => $tenantId, ':id' => $conversationId]);
        $c = $stmt->fetch(PDO::FETCH_ASSOC);

        return is_array($c) ? $this->find($tenantId, (string) $c['channel'], (string) $c['contact_address']) : null;
    }

    public function unblock(int $tenantId, int $id): bool
    {
        $stmt = $this->db->prepare('DELETE FROM chat_blocked_senders WHERE tenant_id = :tid AND id = :id');
        $stmt->execute([':tid' => $tenantId, ':id' => $id]);

        return $stmt->rowCount() > 0;
    }

    /** @return list<array<string, mixed>> every block, newest first */
    public function list(int $tenantId): array
    {
        $stmt = $this->db->prepare('SELECT b.id, b.channel, b.address, b.name, b.reported_spam, b.created_at, p.display_name AS blocked_by
              FROM chat_blocked_senders b LEFT JOIN profiles p ON p.id = b.blocked_by_profile_id
             WHERE b.tenant_id = :tid ORDER BY b.created_at DESC, b.id DESC LIMIT 500');
        $stmt->execute([':tid' => $tenantId]);

        return array_values(array_map(self::present(...), $stmt->fetchAll(PDO::FETCH_ASSOC)));
    }

    /** @return array<string, mixed>|null */
    private function find(int $tenantId, string $channel, string $address): ?array
    {
        $stmt = $this->db->prepare('SELECT b.id, b.channel, b.address, b.name, b.reported_spam, b.created_at, p.display_name AS blocked_by
              FROM chat_blocked_senders b LEFT JOIN profiles p ON p.id = b.blocked_by_profile_id
             WHERE b.tenant_id = :tid AND b.channel = :ch AND b.address_key = :key');
        $stmt->execute([':tid' => $tenantId, ':ch' => $channel, ':key' => self::key($address)]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);

        return is_array($row) ? self::present($row) : null;
    }

    /**
     * @param array<string, mixed> $row
     * @return array<string, mixed>
     */
    private static function present(array $row): array
    {
        return [
            'id' => (int) $row['id'],
            'channel' => (string) $row['channel'],
            'address' => (string) $row['address'],
            'name' => $row['name'] !== null ? (string) $row['name'] : null,
            'reported_spam' => \Whity\Core\Db\DbBool::of($row['reported_spam']),
            'blocked_by' => $row['blocked_by'] !== null ? (string) $row['blocked_by'] : null,
            'created_at' => is_string($row['created_at']) ? gmdate('Y-m-d\TH:i:s\Z', (int) strtotime($row['created_at'] . ' UTC')) : null,
        ];
    }

    private static function key(string $address): string
    {
        return mb_strtolower(trim($address));
    }
}
