<?php

declare(strict_types=1);

namespace Whity\Core\Contacts;

use PDO;
use Whity\Core\i18n\LocalizedText;
use Whity\Core\RBAC\CorePermissions;

/**
 * Everything a customer did here, on their contact's page (merge map,
 * plumbing; UC.3's right pane reads the same): their bookings, their chats,
 * the forms they sent and their requests to join, newest first.
 *
 * Each section shows only to someone who may read that kind of record:
 * bookings with calendar:read, chats with messages:reply, forms with
 * forms:read, join requests with signup:manage. A section the reader can't
 * see is left out, not shown empty, so "nothing here" is never a lie.
 */
final class CustomerActivity
{
    private const LIMIT = 50;

    public function __construct(private readonly PDO $db)
    {
    }

    /**
     * @param callable(string): bool $can whether the reader holds a permission
     * A service's or form's name keeps both languages (`title`, an
     * {"en", "ar"} object); a chat's subject or a person's name is plain text.
     *
     * @return array<string, list<array{id: int, title: string|array<string, string>, status: string, at: string}>>
     */
    public function forContact(int $tenantId, int $contactId, callable $can): array
    {
        $contact = $this->contact($tenantId, $contactId);
        if ($contact === null) {
            return [];
        }
        $out = [];
        if ($can(CorePermissions::CALENDAR_READ)) {
            $out['bookings'] = $this->rows(
                'SELECT b.id, COALESCE(s.name, b.customer_name) AS title, b.status, b.starts_at AS at
                   FROM calendar_bookings b
                   LEFT JOIN calendar_services s ON s.id = b.service_id AND s.tenant_id = b.tenant_id
                  WHERE b.tenant_id = :tenant_id AND b.contact_id = :contact_id
                  ORDER BY b.starts_at DESC, b.id DESC',
                $tenantId,
                $contactId,
            );
        }
        if ($can(CorePermissions::MESSAGES_REPLY)) {
            $out['chats'] = $this->rows(
                "SELECT c.id, COALESCE(c.subject, '') AS title, c.status, c.last_message_at AS at
                   FROM conversations c
                  WHERE c.tenant_id = :tenant_id AND c.kind = 'customer'
                    AND (EXISTS (SELECT 1 FROM conversation_contacts cc WHERE cc.tenant_id = c.tenant_id AND cc.conversation_id = c.id AND cc.contact_id = :contact_id)
                         OR EXISTS (SELECT 1 FROM webchat_visitors v WHERE v.tenant_id = c.tenant_id AND v.conversation_id = c.id AND v.contact_id = :contact_id2))
                  ORDER BY c.last_message_at DESC, c.id DESC",
                $tenantId,
                $contactId,
                [':contact_id2' => $contactId],
            );
        }
        if ($can(CorePermissions::FORMS_READ) && $contact['profile_id'] !== null) {
            $out['forms'] = $this->rows(
                "SELECT s.id, COALESCE(f.name, '') AS title, CASE WHEN s.withdrawn_at IS NULL THEN 'sent' ELSE 'withdrawn' END AS status, s.submitted_at AS at
                   FROM form_submissions s
                   JOIN forms f ON f.id = s.form_id AND f.tenant_id = s.tenant_id
                  WHERE s.tenant_id = :tenant_id AND s.submitted_by_profile_id = :contact_id
                  ORDER BY s.submitted_at DESC, s.id DESC",
                $tenantId,
                (int) $contact['profile_id'],
            );
        }
        if ($can(CorePermissions::SIGNUP_MANAGE)) {
            $out['join_requests'] = $this->rows(
                'SELECT id, display_name AS title, status, created_at AS at
                   FROM customer_signups
                  WHERE tenant_id = :tenant_id AND contact_id = :contact_id
                  ORDER BY created_at DESC, id DESC',
                $tenantId,
                $contactId,
            );
        }

        return $out;
    }

    /** @return string|array<string, string> */
    private static function title(string $stored): string|array
    {
        if (!str_starts_with(ltrim($stored), '{')) {
            return $stored;
        }
        $text = LocalizedText::decode($stored, languages: LocalizedText::BILINGUAL);

        return $text !== [] ? $text : $stored;
    }

    /** @return array{profile_id: ?int}|null */
    private function contact(int $tenantId, int $contactId): ?array
    {
        $stmt = $this->db->prepare('SELECT profile_id FROM contacts WHERE id = :id AND tenant_id = :tenant_id');
        $stmt->execute([':id' => $contactId, ':tenant_id' => $tenantId]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);

        return is_array($row) ? ['profile_id' => $row['profile_id'] !== null ? (int) $row['profile_id'] : null] : null;
    }

    /**
     * @param array<string, int> $extra
     * @return list<array{id: int, title: string|array<string, string>, status: string, at: string}>
     */
    private function rows(string $sql, int $tenantId, int $contactId, array $extra = []): array
    {
        $stmt = $this->db->prepare($sql . ' LIMIT ' . self::LIMIT);
        $stmt->execute([':tenant_id' => $tenantId, ':contact_id' => $contactId] + $extra);

        return array_values(array_map(
            static fn (array $r): array => [
                'id' => (int) $r['id'],
                'title' => self::title((string) $r['title']),
                'status' => (string) $r['status'],
                'at' => (string) ($r['at'] ?? ''),
            ],
            $stmt->fetchAll(PDO::FETCH_ASSOC),
        ));
    }
}
