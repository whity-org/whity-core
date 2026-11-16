<?php

declare(strict_types=1);

namespace Whity\Core\Messaging;

use PDO;

/**
 * Who a customer is, whatever channel they write on (owner Q35.2, merge map
 * C5, WHIT-1121).
 *
 * Maps an address on a channel (an email address, a phone number, a web chat
 * visitor) to the conversation it belongs to, and to the contact it is when
 * known. Filing a message asks here first, so a WhatsApp reply and an email
 * reply from one customer land in one conversation.
 */
final class ConversationContacts
{
    /** How long after closing a conversation a new message still reopens it (owner Q11). */
    public const REOPEN_DAYS = 30;

    public function __construct(private readonly PDO $db)
    {
    }

    /** An address as it is matched: email lowercased, a phone number reduced to its digits and a leading +. */
    public static function key(string $channel, string $address): string
    {
        $address = trim($address);
        if (filter_var($address, FILTER_VALIDATE_EMAIL) !== false) {
            return mb_strtolower($address);
        }
        if (preg_match('/^\+?[\d\s().-]{6,}$/', $address) === 1) {
            return (str_starts_with($address, '+') ? '+' : '') . (string) preg_replace('/\D+/', '', $address);
        }

        return mb_strtolower(mb_substr($address, 0, 320));
    }

    /**
     * The conversation this customer's message continues: by the address on
     * this channel, then by the contact they are on any channel, then by the
     * signed-in customer on any channel. A closed conversation counts only
     * when it closed within {@see self::REOPEN_DAYS}. Null for a new one.
     */
    public function conversationFor(int $tenantId, string $channel, string $address, ?int $contactId, ?int $customerProfileId): ?int
    {
        $live = "(c.status <> 'closed' OR c.closed_at > :cutoff)";
        $cutoff = gmdate('Y-m-d H:i:s', time() - self::REOPEN_DAYS * 86400);

        $byAddress = $this->db->prepare(
            "SELECT c.id FROM conversation_contacts x
               JOIN conversations c ON c.id = x.conversation_id AND c.tenant_id = x.tenant_id
              WHERE x.tenant_id = :tid AND x.channel = :channel AND x.address_key = :key AND {$live}"
        );
        $byAddress->execute([':tid' => $tenantId, ':channel' => $channel, ':key' => self::key($channel, $address), ':cutoff' => $cutoff]);
        $id = $byAddress->fetchColumn();
        if ($id !== false) {
            return (int) $id;
        }

        if ($contactId !== null) {
            $byContact = $this->db->prepare(
                "SELECT c.id FROM conversation_contacts x
                   JOIN conversations c ON c.id = x.conversation_id AND c.tenant_id = x.tenant_id
                  WHERE x.tenant_id = :tid AND x.contact_id = :contact AND {$live}
                  ORDER BY c.last_message_at DESC, c.id DESC LIMIT 1"
            );
            $byContact->execute([':tid' => $tenantId, ':contact' => $contactId, ':cutoff' => $cutoff]);
            $id = $byContact->fetchColumn();
            if ($id !== false) {
                return (int) $id;
            }

            // Addresses mapped before the contact was known (or before C5):
            // any of the contact's own addresses, on any channel.
            $keys = $this->contactKeys($tenantId, $contactId);
            if ($keys !== []) {
                $params = [':tid' => $tenantId, ':cutoff' => $cutoff];
                $in = [];
                foreach ($keys as $i => $key) {
                    $in[] = ':k' . $i;
                    $params[':k' . $i] = $key;
                }
                $byAddresses = $this->db->prepare(
                    "SELECT c.id FROM conversation_contacts x
                       JOIN conversations c ON c.id = x.conversation_id AND c.tenant_id = x.tenant_id
                      WHERE x.tenant_id = :tid AND x.address_key IN (" . implode(', ', $in) . ") AND {$live}
                      ORDER BY c.last_message_at DESC, c.id DESC LIMIT 1"
                );
                $byAddresses->execute($params);
                $id = $byAddresses->fetchColumn();
                if ($id !== false) {
                    return (int) $id;
                }
            }
        }

        if ($customerProfileId !== null) {
            $byCustomer = $this->db->prepare(
                "SELECT c.id FROM conversations c
                  WHERE c.tenant_id = :tid AND c.customer_profile_id = :pid AND c.kind = 'customer' AND {$live}
                  ORDER BY c.last_message_at DESC, c.id DESC LIMIT 1"
            );
            $byCustomer->execute([':tid' => $tenantId, ':pid' => $customerProfileId, ':cutoff' => $cutoff]);
            $id = $byCustomer->fetchColumn();
            if ($id !== false) {
                return (int) $id;
            }
        }

        return null;
    }

    /** The workspace contact with this email address or phone number, if any. */
    public function contactFor(int $tenantId, string $address): ?int
    {
        $key = self::key('', $address);
        if (str_contains($key, '@')) {
            $stmt = $this->db->prepare('SELECT id FROM contacts WHERE tenant_id = :tid AND LOWER(email) = :key ORDER BY id LIMIT 1');
            $stmt->execute([':tid' => $tenantId, ':key' => $key]);
        } else {
            $digits = ltrim($key, '+');
            if (strlen($digits) < 6) {
                return null;
            }
            // Phone numbers are stored as typed; compare their last digits so +962 79… and 079… meet.
            $stmt = $this->db->prepare("SELECT id, mobile, phone FROM contacts WHERE tenant_id = :tid AND (mobile <> '' OR phone <> '') ORDER BY id");
            $stmt->execute([':tid' => $tenantId]);
            foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
                foreach ([$row['mobile'], $row['phone']] as $number) {
                    $theirs = (string) preg_replace('/\D+/', '', (string) $number);
                    if (strlen($theirs) >= 6 && substr($theirs, -9) === substr($digits, -9)) {
                        return (int) $row['id'];
                    }
                }
            }

            return null;
        }
        $id = $stmt->fetchColumn();

        return $id === false ? null : (int) $id;
    }

    /**
     * The contact's addresses as they are matched: email, and each phone number
     * in its stored form and as +digits.
     *
     * @return list<string>
     */
    private function contactKeys(int $tenantId, int $contactId): array
    {
        $stmt = $this->db->prepare('SELECT email, mobile, phone FROM contacts WHERE tenant_id = :tid AND id = :id');
        $stmt->execute([':tid' => $tenantId, ':id' => $contactId]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!is_array($row)) {
            return [];
        }
        $keys = [];
        foreach ([$row['email'], $row['mobile'], $row['phone']] as $address) {
            $address = trim((string) $address);
            if ($address === '') {
                continue;
            }
            $keys[] = self::key('', $address);
            $digits = (string) preg_replace('/\D+/', '', $address);
            if (strlen($digits) >= 6) {
                $keys[] = '+' . ltrim($digits, '0');
                $keys[] = $digits;
            }
        }

        return array_values(array_unique($keys));
    }

    /** Remember that this address on this channel belongs to this conversation (and contact). */
    public function remember(int $tenantId, string $channel, string $address, int $conversationId, ?int $contactId): void
    {
        $key = self::key($channel, $address);
        if ($key === '') {
            return;
        }
        $update = $this->db->prepare(
            'UPDATE conversation_contacts SET conversation_id = :cid, contact_id = COALESCE(:contact, contact_id), updated_at = NOW()
              WHERE tenant_id = :tid AND channel = :channel AND address_key = :key'
        );
        $update->bindValue(':cid', $conversationId, PDO::PARAM_INT);
        $update->bindValue(':contact', $contactId, $contactId === null ? PDO::PARAM_NULL : PDO::PARAM_INT);
        $update->bindValue(':tid', $tenantId, PDO::PARAM_INT);
        $update->bindValue(':channel', $channel);
        $update->bindValue(':key', $key);
        $update->execute();
        if ($update->rowCount() > 0) {
            return;
        }
        $insert = $this->db->prepare(
            'INSERT INTO conversation_contacts (tenant_id, channel, address_key, conversation_id, contact_id)
             VALUES (:tid, :channel, :key, :cid, :contact)'
        );
        $insert->bindValue(':tid', $tenantId, PDO::PARAM_INT);
        $insert->bindValue(':channel', $channel);
        $insert->bindValue(':key', $key);
        $insert->bindValue(':cid', $conversationId, PDO::PARAM_INT);
        $insert->bindValue(':contact', $contactId, $contactId === null ? PDO::PARAM_NULL : PDO::PARAM_INT);
        $insert->execute();
    }

    /**
     * The addresses this conversation has, by channel, most recently used
     * first: what "Send by …" offers (UC.3).
     *
     * @return array<string, string> channel => address
     */
    public function addresses(int $tenantId, int $conversationId): array
    {
        $stmt = $this->db->prepare(
            "SELECT channel, channel_address FROM messages
              WHERE tenant_id = :tid AND conversation_id = :cid AND kind = 'inbound' AND channel IS NOT NULL AND channel_address IS NOT NULL
              ORDER BY id DESC"
        );
        $stmt->execute([':tid' => $tenantId, ':cid' => $conversationId]);
        $out = [];
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
            $out[(string) $row['channel']] ??= (string) $row['channel_address'];
        }

        return $out;
    }
}
