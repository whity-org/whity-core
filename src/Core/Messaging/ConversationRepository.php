<?php

declare(strict_types=1);

namespace Whity\Core\Messaging;

use PDO;
use Whity\Core\Text\SearchText;

/**
 * `conversations` (migration 243). Every statement binds `tenant_id`, so an id
 * from another workspace reads as not found.
 */
final class ConversationRepository
{
    public const STATUSES = ['open', 'pending', 'closed'];

    private const COLUMNS = 'c.id, c.tenant_id, c.channel, c.subject, c.status, c.assigned_profile_id,
        c.customer_profile_id, c.contact_name, c.contact_address, c.last_message_at, c.last_inbound_at,
        c.created_at, c.updated_at, c.closed_at,
        ap.display_name AS assigned_name, cp.display_name AS customer_name';

    private const JOINS = 'LEFT JOIN profiles ap ON ap.id = c.assigned_profile_id
        LEFT JOIN profiles cp ON cp.id = c.customer_profile_id';

    public function __construct(private readonly PDO $db)
    {
    }

    public function create(
        int $tenantId,
        string $channel,
        string $contactAddress,
        ?string $contactName,
        ?string $subject,
        ?int $customerProfileId,
    ): int {
        $stmt = $this->db->prepare(
            'INSERT INTO conversations (tenant_id, channel, subject, status, customer_profile_id, contact_name, contact_address,
                                        last_message_at, created_at, updated_at)
             VALUES (:tid, :channel, :subject, \'open\', :customer, :name, :address, NOW(), NOW(), NOW())
             RETURNING id'
        );
        $stmt->execute([
            ':tid' => $tenantId,
            ':channel' => $channel,
            ':subject' => $subject !== null ? mb_substr($subject, 0, 255) : null,
            ':customer' => $customerProfileId,
            ':name' => $contactName !== null ? mb_substr($contactName, 0, 255) : null,
            ':address' => mb_substr($contactAddress, 0, 320),
        ]);

        return (int) $stmt->fetchColumn();
    }

    /** @return array<string, mixed>|null */
    public function find(int $tenantId, int $id): ?array
    {
        $stmt = $this->db->prepare('SELECT ' . self::COLUMNS . ' FROM conversations c ' . self::JOINS
            . ' WHERE c.tenant_id = :tid AND c.id = :id');
        $stmt->execute([':tid' => $tenantId, ':id' => $id]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);

        return $row === false ? null : $row;
    }

    /**
     * A customer's open conversation on a channel, by the customer it is linked
     * to rather than by address (a WhatsApp conversation is kept under the
     * phone number the customer wrote from).
     *
     * @return array<string, mixed>|null
     */
    public function findOpenForCustomer(int $tenantId, string $channel, int $profileId): ?array
    {
        $stmt = $this->db->prepare('SELECT ' . self::COLUMNS . ' FROM conversations c ' . self::JOINS
            . ' WHERE c.tenant_id = :tid AND c.channel = :channel AND c.customer_profile_id = :pid
                AND c.status <> \'closed\'
              ORDER BY c.last_message_at DESC, c.id DESC LIMIT 1');
        $stmt->execute([':tid' => $tenantId, ':channel' => $channel, ':pid' => $profileId]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);

        return $row === false ? null : $row;
    }

    /**
     * The contact's most recent conversation on the channel that is not closed.
     *
     * @return array<string, mixed>|null
     */
    public function findOpenForContact(int $tenantId, string $channel, string $address): ?array
    {
        $stmt = $this->db->prepare('SELECT ' . self::COLUMNS . ' FROM conversations c ' . self::JOINS
            . ' WHERE c.tenant_id = :tid AND c.channel = :channel AND LOWER(c.contact_address) = LOWER(:address)
                AND c.status <> \'closed\'
              ORDER BY c.last_message_at DESC, c.id DESC LIMIT 1');
        $stmt->execute([':tid' => $tenantId, ':channel' => $channel, ':address' => $address]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);

        return $row === false ? null : $row;
    }

    /**
     * An open email conversation with the same sender and subject (after
     * stripping reply prefixes), for mail that lost its threading headers.
     *
     * @return array<string, mixed>|null
     */
    public function findBySubject(int $tenantId, string $channel, string $address, string $subject): ?array
    {
        $stmt = $this->db->prepare('SELECT ' . self::COLUMNS . ' FROM conversations c ' . self::JOINS
            . ' WHERE c.tenant_id = :tid AND c.channel = :channel AND LOWER(c.contact_address) = LOWER(:address)
                AND c.status <> \'closed\' AND LOWER(c.subject) = LOWER(:subject)
              ORDER BY c.last_message_at DESC, c.id DESC LIMIT 1');
        $stmt->execute([':tid' => $tenantId, ':channel' => $channel, ':address' => $address, ':subject' => $subject]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);

        return $row === false ? null : $row;
    }

    /**
     * A page of the staff inbox, newest activity first, keyset-paged on
     * (last_message_at, id).
     *
     * @param array{status?: ?string, assignee?: int|string|null, channel?: ?string, q?: ?string, viewer?: int} $filters
     *        `assignee`: a profile id, `me` (needs `viewer`), or `none`.
     * @param array{0: string, 1: int}|null $after the last row's (last_message_at, id)
     *
     * @return list<array<string, mixed>>
     */
    public function list(int $tenantId, array $filters, ?array $after, int $limit): array
    {
        [$where, $params] = self::filters($tenantId, $filters);
        if ($after !== null) {
            $where .= ' AND (c.last_message_at < :after_at OR (c.last_message_at = :after_at2 AND c.id < :after_id))';
            $params[':after_at'] = $after[0];
            $params[':after_at2'] = $after[0];
            $params[':after_id'] = $after[1];
        }

        $stmt = $this->db->prepare('SELECT ' . self::COLUMNS . ',
                (SELECT m.body_text FROM messages m
                  WHERE m.tenant_id = c.tenant_id AND m.conversation_id = c.id AND m.kind <> \'note\'
                  ORDER BY m.id DESC LIMIT 1) AS last_body
              FROM conversations c ' . self::JOINS . ' WHERE c.tenant_id = :tid' . $where
            . ' ORDER BY c.last_message_at DESC, c.id DESC LIMIT :limit');
        foreach ($params as $key => $value) {
            $stmt->bindValue($key, $value, is_int($value) ? PDO::PARAM_INT : PDO::PARAM_STR);
        }
        $stmt->bindValue(':limit', $limit, PDO::PARAM_INT);
        $stmt->execute();

        return array_values($stmt->fetchAll(PDO::FETCH_ASSOC));
    }

    /** @param array{status?: ?string, assignee?: int|string|null, channel?: ?string, q?: ?string, viewer?: int} $filters */
    public function count(int $tenantId, array $filters): int
    {
        [$where, $params] = self::filters($tenantId, $filters);
        $stmt = $this->db->prepare('SELECT COUNT(*) FROM conversations c WHERE c.tenant_id = :tid' . $where);
        foreach ($params as $key => $value) {
            $stmt->bindValue($key, $value, is_int($value) ? PDO::PARAM_INT : PDO::PARAM_STR);
        }
        $stmt->execute();

        return (int) $stmt->fetchColumn();
    }

    /** @return array<string, int> status => count, for the inbox tabs */
    public function countsByStatus(int $tenantId): array
    {
        $stmt = $this->db->prepare('SELECT status, COUNT(*) AS n FROM conversations WHERE tenant_id = :tid GROUP BY status');
        $stmt->execute([':tid' => $tenantId]);
        $counts = array_fill_keys(self::STATUSES, 0);
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
            $counts[(string) $row['status']] = (int) $row['n'];
        }

        return $counts;
    }

    /**
     * Open conversations assigned to the person, for their inbox (newest first,
     * keyset on last_message_at, id).
     *
     * @param array{0: string, 1: int}|null $after
     *
     * @return list<array<string, mixed>>
     */
    public function assignedOpen(int $tenantId, int $profileId, bool $openOnly, ?array $after, int $limit): array
    {
        $filters = ['assignee' => $profileId, 'status' => $openOnly ? 'open' : null];

        return $this->list($tenantId, $filters, $after, $limit);
    }

    /** @return list<array<string, mixed>> the customer's own conversations, newest first */
    public function listForCustomer(int $tenantId, int $profileId, int $limit): array
    {
        $stmt = $this->db->prepare('SELECT ' . self::COLUMNS . ' FROM conversations c ' . self::JOINS
            . ' WHERE c.tenant_id = :tid AND c.customer_profile_id = :pid
              ORDER BY c.last_message_at DESC, c.id DESC LIMIT :limit');
        $stmt->bindValue(':tid', $tenantId, PDO::PARAM_INT);
        $stmt->bindValue(':pid', $profileId, PDO::PARAM_INT);
        $stmt->bindValue(':limit', $limit, PDO::PARAM_INT);
        $stmt->execute();

        return array_values($stmt->fetchAll(PDO::FETCH_ASSOC));
    }

    /** New activity: bump the time, and reopen a closed conversation when the customer writes. */
    public function touch(int $tenantId, int $id, bool $inbound): void
    {
        $sql = $inbound
            ? 'UPDATE conversations SET last_message_at = NOW(), last_inbound_at = NOW(), updated_at = NOW(),
                    status = CASE WHEN status = \'closed\' THEN \'open\' ELSE status END,
                    closed_at = CASE WHEN status = \'closed\' THEN NULL ELSE closed_at END
                WHERE tenant_id = :tid AND id = :id'
            : 'UPDATE conversations SET last_message_at = NOW(), updated_at = NOW() WHERE tenant_id = :tid AND id = :id';
        $this->db->prepare($sql)->execute([':tid' => $tenantId, ':id' => $id]);
    }

    public function setStatus(int $tenantId, int $id, string $status): bool
    {
        $stmt = $this->db->prepare(
            'UPDATE conversations SET status = :status, updated_at = NOW(),
                    closed_at = CASE WHEN :status2 = \'closed\' THEN NOW() ELSE NULL END
              WHERE tenant_id = :tid AND id = :id'
        );
        $stmt->execute([':status' => $status, ':status2' => $status, ':tid' => $tenantId, ':id' => $id]);

        return $stmt->rowCount() > 0;
    }

    public function assign(int $tenantId, int $id, ?int $profileId): bool
    {
        $stmt = $this->db->prepare(
            'UPDATE conversations SET assigned_profile_id = :pid, updated_at = NOW() WHERE tenant_id = :tid AND id = :id'
        );
        $stmt->bindValue(':pid', $profileId, $profileId === null ? PDO::PARAM_NULL : PDO::PARAM_INT);
        $stmt->bindValue(':tid', $tenantId, PDO::PARAM_INT);
        $stmt->bindValue(':id', $id, PDO::PARAM_INT);
        $stmt->execute();

        return $stmt->rowCount() > 0;
    }

    public function linkCustomer(int $tenantId, int $id, int $profileId): void
    {
        $this->db->prepare(
            'UPDATE conversations SET customer_profile_id = :pid WHERE tenant_id = :tid AND id = :id AND customer_profile_id IS NULL'
        )->execute([':pid' => $profileId, ':tid' => $tenantId, ':id' => $id]);
    }

    /** Whether the person is staff of the workspace (an active, non-customer member). */
    public function isStaff(int $tenantId, int $profileId): bool
    {
        $stmt = $this->db->prepare(
            'SELECT 1 FROM memberships WHERE tenant_id = :tid AND profile_id = :pid AND status = \'active\' AND kind = \'member\''
        );
        $stmt->execute([':tid' => $tenantId, ':pid' => $profileId]);

        return $stmt->fetchColumn() !== false;
    }

    /**
     * The workspace customer whose primary or any verified address this is, if any.
     */
    public function customerByEmail(int $tenantId, string $email): ?int
    {
        $stmt = $this->db->prepare(
            'SELECT m.profile_id FROM memberships m
               JOIN profile_emails e ON e.profile_id = m.profile_id
              WHERE m.tenant_id = :tid AND m.kind = \'customer\' AND m.status = \'active\'
                AND LOWER(e.email) = LOWER(:email)
              ORDER BY m.profile_id LIMIT 1'
        );
        $stmt->execute([':tid' => $tenantId, ':email' => $email]);
        $id = $stmt->fetchColumn();

        return $id === false ? null : (int) $id;
    }

    /**
     * @param array{status?: ?string, assignee?: int|string|null, channel?: ?string, q?: ?string, viewer?: int} $filters
     *
     * @return array{0: string, 1: array<string, int|string>}
     */
    private static function filters(int $tenantId, array $filters): array
    {
        $where = '';
        $params = [':tid' => $tenantId];

        $status = $filters['status'] ?? null;
        if (is_string($status) && in_array($status, self::STATUSES, true)) {
            $where .= ' AND c.status = :status';
            $params[':status'] = $status;
        }

        $assignee = $filters['assignee'] ?? null;
        if ($assignee === 'me' && isset($filters['viewer'])) {
            $assignee = $filters['viewer'];
        }
        if ($assignee === 'none') {
            $where .= ' AND c.assigned_profile_id IS NULL';
        } elseif (is_int($assignee)) {
            $where .= ' AND c.assigned_profile_id = :assignee';
            $params[':assignee'] = $assignee;
        }

        $channel = $filters['channel'] ?? null;
        if (is_string($channel) && $channel !== '') {
            $where .= ' AND c.channel = :channel';
            $params[':channel'] = $channel;
        }

        $q = trim((string) ($filters['q'] ?? ''));
        if ($q !== '') {
            $pattern = SearchText::containsPattern(SearchText::fold(mb_substr($q, 0, 100)));
            $where .= ' AND (' . SearchText::sqlFold('c.contact_address') . " LIKE :q1 ESCAPE '\\'"
                . ' OR ' . SearchText::sqlFold("COALESCE(c.contact_name, '')") . " LIKE :q2 ESCAPE '\\'"
                . ' OR ' . SearchText::sqlFold("COALESCE(c.subject, '')") . " LIKE :q3 ESCAPE '\\'"
                . ' OR EXISTS (SELECT 1 FROM messages sm WHERE sm.tenant_id = c.tenant_id AND sm.conversation_id = c.id AND '
                . SearchText::sqlFold('sm.body_text') . " LIKE :q4 ESCAPE '\\'))";
            $params[':q1'] = $pattern;
            $params[':q2'] = $pattern;
            $params[':q3'] = $pattern;
            $params[':q4'] = $pattern;
        }

        return [$where, $params];
    }
}
