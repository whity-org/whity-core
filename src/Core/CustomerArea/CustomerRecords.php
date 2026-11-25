<?php

declare(strict_types=1);

namespace Whity\Core\CustomerArea;

use PDO;
use Whity\Core\Calendar\BookingRepository;
use Whity\Core\Calendar\Interval;
use Whity\Core\Db\DbBool;
use Whity\Core\i18n\LocalizedText;

/**
 * A signed-in customer's own records in one workspace (design section 41 §6,
 * CA.9, CA.10).
 *
 * WHOSE RECORDS. A person's records are those of the contacts in this
 * workspace whose `profile_id` is theirs. Every query here binds the tenant
 * AND that list of contacts (or the profile itself), resolved on the server
 * from the session; nothing a client sends widens it. A record that is not
 * theirs is indistinguishable from one that does not exist.
 *
 * BOOKINGS ARE NARROWER. A public booking finds its contact by the email or
 * mobile typed into the form, unverified, so a contact can carry a booking
 * someone else made with this person's phone number. A booking shows only
 * when it is theirs by profile, or was made with one of their VERIFIED
 * addresses.
 *
 * Only customer-safe fields leave this class: no internal notes, no no-show
 * flags (AP.21), no cost prices, no ids of staff or agents.
 */
final class CustomerRecords
{
    /** What a customer sees of a quote: sent and after, never drafts. */
    public const QUOTE_STATUSES = ['sent', 'accepted', 'declined', 'expired', 'converted'];

    /** What a customer sees of an invoice: issued and after. */
    public const INVOICE_STATUSES = ['issued', 'partly_paid', 'paid', 'credited'];

    /** The invoice kinds a customer is sent. */
    public const INVOICE_KINDS = ['sale', 'sale_credit'];

    private const LIMIT = 100;

    public function __construct(private readonly PDO $db)
    {
    }

    /**
     * The person's verified email addresses, lower case.
     *
     * @return list<string>
     */
    public function verifiedEmails(int $profileId): array
    {
        // profile_emails is a sanctioned GLOBAL identity table (ADR 0005); the id is the caller's own, from their validated session.
        $stmt = $this->db->prepare('SELECT email FROM profile_emails WHERE profile_id = :pid AND verified = TRUE');
        $stmt->execute([':pid' => $profileId]);

        return array_values(array_unique(array_map(
            static fn (mixed $e): string => strtolower(trim((string) $e)),
            $stmt->fetchAll(PDO::FETCH_COLUMN)
        )));
    }

    /**
     * Link this workspace's unlinked contacts that carry one of the person's
     * VERIFIED email addresses to them (CA.9, Q5). An unverified address never
     * links. Returns the ids it linked, for the audit log.
     *
     * @return list<int>
     */
    public function linkVerified(int $tenantId, int $profileId): array
    {
        $emails = $this->verifiedEmails($profileId);
        if ($emails === []) {
            return [];
        }
        [$in, $bind] = self::inList('e', $emails);
        $stmt = $this->db->prepare(
            "SELECT id FROM contacts
              WHERE tenant_id = :tid AND profile_id IS NULL AND archived_at IS NULL AND LOWER(email) IN ($in)"
        );
        $stmt->execute([':tid' => $tenantId] + $bind);
        $ids = array_values(array_map('intval', $stmt->fetchAll(PDO::FETCH_COLUMN)));
        if ($ids === []) {
            return [];
        }
        [$idIn, $idBind] = self::inList('c', $ids);
        $this->db->prepare(
            "UPDATE contacts SET profile_id = :pid, updated_at = CURRENT_TIMESTAMP
              WHERE tenant_id = :tid AND profile_id IS NULL AND id IN ($idIn)"
        )->execute([':pid' => $profileId, ':tid' => $tenantId] + $idBind);

        return $ids;
    }

    /**
     * The person's contacts in this workspace.
     *
     * @return list<int>
     */
    public function contactIds(int $tenantId, int $profileId): array
    {
        $stmt = $this->db->prepare(
            'SELECT id FROM contacts WHERE tenant_id = :tid AND profile_id = :pid AND archived_at IS NULL ORDER BY id'
        );
        $stmt->execute([':tid' => $tenantId, ':pid' => $profileId]);

        return array_values(array_map('intval', $stmt->fetchAll(PDO::FETCH_COLUMN)));
    }

    // ---- Bookings ----

    /**
     * @param 'upcoming'|'past' $when
     * @return list<array<string, mixed>>
     */
    public function bookings(int $tenantId, int $profileId, string $when, string $now): array
    {
        [$mine, $bind] = $this->bookingIsMine($tenantId, $profileId);
        if ($mine === null) {
            return [];
        }
        $timeCond = $when === 'upcoming'
            ? 'b.ends_at >= :now AND b.status IN (' . self::quoted(BookingRepository::LIVE) . ')'
            : '(b.ends_at < :now OR b.status NOT IN (' . self::quoted(BookingRepository::LIVE) . '))';
        $order = $when === 'upcoming' ? 'b.starts_at ASC' : 'b.starts_at DESC';
        $stmt = $this->db->prepare(
            'SELECT b.id, b.status, b.starts_at, b.ends_at, b.price_minor, b.currency, b.checked_in_at,
                    s.name AS service_name, p.display_name AS staff_name, r.name AS room_name
               FROM calendar_bookings b
               LEFT JOIN calendar_services s ON s.id = b.service_id AND s.tenant_id = b.tenant_id
               LEFT JOIN profiles p ON p.id = b.profile_id
               LEFT JOIN calendar_rooms r ON r.id = b.room_id AND r.tenant_id = b.tenant_id
              WHERE b.tenant_id = :tid AND ' . $mine . ' AND ' . $timeCond . '
              ORDER BY ' . $order . ' LIMIT ' . self::LIMIT
        );
        $stmt->execute([':tid' => $tenantId, ':now' => $now] + $bind);

        return array_values(array_map([$this, 'bookingRow'], $stmt->fetchAll(PDO::FETCH_ASSOC)));
    }

    /** Whether the booking is the person's (CA.9, with the narrower booking rule). */
    public function ownsBooking(int $tenantId, int $profileId, int $bookingId): bool
    {
        [$mine, $bind] = $this->bookingIsMine($tenantId, $profileId);
        if ($mine === null || $bookingId <= 0) {
            return false;
        }
        $stmt = $this->db->prepare('SELECT 1 FROM calendar_bookings b WHERE b.tenant_id = :tid AND b.id = :id AND ' . $mine);
        $stmt->execute([':tid' => $tenantId, ':id' => $bookingId] + $bind);

        return $stmt->fetchColumn() !== false;
    }

    public function hasBookings(int $tenantId, int $profileId): bool
    {
        [$mine, $bind] = $this->bookingIsMine($tenantId, $profileId);
        if ($mine === null) {
            return false;
        }
        $stmt = $this->db->prepare('SELECT 1 FROM calendar_bookings b WHERE b.tenant_id = :tid AND ' . $mine . ' LIMIT 1');
        $stmt->execute([':tid' => $tenantId] + $bind);

        return $stmt->fetchColumn() !== false;
    }

    // ---- Bills: quotes, invoices, receipts ----

    /**
     * Quotes, invoices and credit notes, and receipts, newest first (CA.15).
     *
     * @param ''|'quote'|'invoice'|'credit_note'|'receipt' $kind
     * @return list<array<string, mixed>>
     */
    public function bills(int $tenantId, int $profileId, string $kind = ''): array
    {
        $contacts = $this->contactIds($tenantId, $profileId);
        if ($contacts === []) {
            return [];
        }
        [$in, $bind] = self::inList('c', $contacts);
        $rows = [];
        if ($kind === '' || $kind === 'quote') {
            $stmt = $this->db->prepare(
                'SELECT id, number, revision, status, issue_date, valid_until, currency, total_minor
                   FROM sales_quotes
                  WHERE tenant_id = :tid AND contact_id IN (' . $in . ') AND status IN (' . self::quoted(self::QUOTE_STATUSES) . ')
                  ORDER BY issue_date DESC, id DESC LIMIT ' . self::LIMIT
            );
            $stmt->execute([':tid' => $tenantId] + $bind);
            foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $r) {
                $rows[] = [
                    'kind' => 'quote',
                    'id' => (int) $r['id'],
                    'number' => (string) $r['number'],
                    'revision' => (int) $r['revision'],
                    'status' => (string) $r['status'],
                    'date' => self::day($r['issue_date']),
                    'valid_until' => self::day($r['valid_until']),
                    'due_date' => null,
                    'currency' => (string) $r['currency'],
                    'total_minor' => (int) $r['total_minor'],
                    'due_minor' => 0,
                    'waiting' => $r['status'] === 'sent',
                ];
            }
        }
        if ($kind === '' || $kind === 'invoice' || $kind === 'credit_note') {
            $kinds = $kind === 'invoice' ? ['sale'] : ($kind === 'credit_note' ? ['sale_credit'] : self::INVOICE_KINDS);
            $stmt = $this->db->prepare(
                'SELECT id, kind, number, status, issue_date, due_date, currency, total_minor, paid_minor, credited_minor
                   FROM acc_invoices
                  WHERE tenant_id = :tid AND contact_id IN (' . $in . ')
                    AND kind IN (' . self::quoted($kinds) . ') AND status IN (' . self::quoted(self::INVOICE_STATUSES) . ')
                  ORDER BY issue_date DESC, id DESC LIMIT ' . self::LIMIT
            );
            $stmt->execute([':tid' => $tenantId] + $bind);
            foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $r) {
                $rows[] = $this->invoiceRow($r);
            }
        }
        if ($kind === '' || $kind === 'receipt') {
            $stmt = $this->db->prepare(
                "SELECT id, number, paid_on, currency, amount_minor
                   FROM acc_payments
                  WHERE tenant_id = :tid AND contact_id IN ($in) AND direction = 'in' AND status = 'posted'
                  ORDER BY paid_on DESC, id DESC LIMIT " . self::LIMIT
            );
            $stmt->execute([':tid' => $tenantId] + $bind);
            foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $r) {
                $rows[] = [
                    'kind' => 'receipt',
                    'id' => (int) $r['id'],
                    'number' => (string) $r['number'],
                    'status' => 'paid',
                    'date' => self::day($r['paid_on']),
                    'valid_until' => null,
                    'due_date' => null,
                    'currency' => (string) $r['currency'],
                    'total_minor' => (int) $r['amount_minor'],
                    'due_minor' => 0,
                    'waiting' => false,
                ];
            }
        }
        usort($rows, static fn (array $a, array $b): int => [(string) $b['date'], $b['id']] <=> [(string) $a['date'], $a['id']]);

        return array_slice($rows, 0, self::LIMIT);
    }

    /** The quote's id when it is the person's and a customer may see it, else null. */
    public function ownsQuote(int $tenantId, int $profileId, int $quoteId): bool
    {
        return $this->owns('sales_quotes', $tenantId, $profileId, $quoteId, 'status IN (' . self::quoted(self::QUOTE_STATUSES) . ')');
    }

    /**
     * One of the person's invoices or credit notes, with its lines; null when
     * it is not theirs or not issued.
     *
     * @return array<string, mixed>|null
     */
    public function invoice(int $tenantId, int $profileId, int $id): ?array
    {
        if (!$this->owns('acc_invoices', $tenantId, $profileId, $id, 'kind IN (' . self::quoted(self::INVOICE_KINDS) . ') AND status IN (' . self::quoted(self::INVOICE_STATUSES) . ')')) {
            return null;
        }
        $stmt = $this->db->prepare(
            'SELECT id, kind, number, status, issue_date, due_date, currency, subtotal_minor, discount_minor, tax_minor,
                    total_minor, paid_minor, credited_minor, prices_include_tax
               FROM acc_invoices WHERE tenant_id = :tid AND id = :id'
        );
        $stmt->execute([':tid' => $tenantId, ':id' => $id]);
        $r = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!is_array($r)) {
            return null;
        }
        $lines = $this->db->prepare(
            'SELECT description, quantity, quantity_decimals, unit_price_minor, discount_bp, tax_minor, total_minor
               FROM acc_invoice_lines WHERE tenant_id = :tid AND invoice_id = :id ORDER BY line_no'
        );
        $lines->execute([':tid' => $tenantId, ':id' => $id]);

        return $this->invoiceRow($r) + [
            'subtotal_minor' => (int) $r['subtotal_minor'],
            'discount_minor' => (int) $r['discount_minor'],
            'tax_minor' => (int) $r['tax_minor'],
            'paid_minor' => (int) $r['paid_minor'],
            'credited_minor' => (int) $r['credited_minor'],
            'prices_include_tax' => DbBool::of($r['prices_include_tax']),
            'lines' => array_map(static fn (array $l): array => [
                'description' => (string) $l['description'],
                'quantity' => self::quantity((int) $l['quantity'], (int) $l['quantity_decimals']),
                'unit_price_minor' => (int) $l['unit_price_minor'],
                'discount_bp' => (int) $l['discount_bp'],
                'tax_minor' => (int) $l['tax_minor'],
                'total_minor' => (int) $l['total_minor'],
            ], $lines->fetchAll(PDO::FETCH_ASSOC)),
        ];
    }

    /**
     * One of the person's receipts; null when it is not theirs.
     *
     * @return array<string, mixed>|null
     */
    public function receipt(int $tenantId, int $profileId, int $id): ?array
    {
        if (!$this->owns('acc_payments', $tenantId, $profileId, $id, "direction = 'in' AND status = 'posted'")) {
            return null;
        }
        $stmt = $this->db->prepare(
            'SELECT id, number, paid_on, currency, amount_minor, method, reference FROM acc_payments WHERE tenant_id = :tid AND id = :id'
        );
        $stmt->execute([':tid' => $tenantId, ':id' => $id]);
        $r = $stmt->fetch(PDO::FETCH_ASSOC);

        return is_array($r) ? [
            'kind' => 'receipt',
            'id' => (int) $r['id'],
            'number' => (string) $r['number'],
            'date' => self::day($r['paid_on']),
            'currency' => (string) $r['currency'],
            'total_minor' => (int) $r['amount_minor'],
            'method' => (string) $r['method'],
            'reference' => (string) $r['reference'],
        ] : null;
    }

    /** What is waiting for the person: quotes to answer and invoices with something due. */
    public function waitingCount(int $tenantId, int $profileId): int
    {
        return count(array_filter($this->bills($tenantId, $profileId), static fn (array $b): bool => $b['waiting'] === true));
    }

    // ---- Forms ----

    /**
     * The forms the person sent this workspace, newest first.
     *
     * @return list<array<string, mixed>>
     */
    public function formSubmissions(int $tenantId, int $profileId): array
    {
        $stmt = $this->db->prepare(
            'SELECT s.id, s.reference, s.submitted_at, s.withdrawn_at, f.name AS form_name
               FROM form_submissions s JOIN forms f ON f.id = s.form_id AND f.tenant_id = s.tenant_id
              WHERE s.tenant_id = :tid AND s.submitted_by_profile_id = :pid
              ORDER BY s.submitted_at DESC, s.id DESC LIMIT ' . self::LIMIT
        );
        $stmt->execute([':tid' => $tenantId, ':pid' => $profileId]);

        return array_values(array_map(static fn (array $r): array => [
            'id' => (int) $r['id'],
            'reference' => is_string($r['reference']) ? $r['reference'] : null,
            'form_name' => LocalizedText::decode(is_string($r['form_name']) ? $r['form_name'] : ''),
            'submitted_at' => is_string($r['submitted_at']) ? $r['submitted_at'] : null,
            'withdrawn' => $r['withdrawn_at'] !== null,
        ], $stmt->fetchAll(PDO::FETCH_ASSOC)));
    }

    // ---- Helpers ----

    /**
     * The booking predicate for this person (alias `b`) and its bindings, or
     * null when nothing can be theirs.
     *
     * @return array{0: ?string, 1: array<string, int|string>}
     */
    private function bookingIsMine(int $tenantId, int $profileId): array
    {
        $contacts = $this->contactIds($tenantId, $profileId);
        $emails = $this->verifiedEmails($profileId);
        $or = ['b.customer_profile_id = :me'];
        $bind = [':me' => $profileId];
        if ($contacts !== [] && $emails !== []) {
            [$cIn, $cBind] = self::inList('bc', $contacts);
            [$eIn, $eBind] = self::inList('be', $emails);
            $or[] = "(b.contact_id IN ($cIn) AND LOWER(b.customer_email) IN ($eIn))";
            $bind += $cBind + $eBind;
        }

        return ['(' . implode(' OR ', $or) . ')', $bind];
    }

    private function owns(string $table, int $tenantId, int $profileId, int $id, string $extra): bool
    {
        if ($id <= 0) {
            return false;
        }
        $contacts = $this->contactIds($tenantId, $profileId);
        if ($contacts === []) {
            return false;
        }
        [$in, $bind] = self::inList('c', $contacts);
        $stmt = $this->db->prepare("SELECT 1 FROM $table WHERE tenant_id = :tid AND id = :id AND contact_id IN ($in) AND $extra");
        $stmt->execute([':tid' => $tenantId, ':id' => $id] + $bind);

        return $stmt->fetchColumn() !== false;
    }

    /**
     * @param array<string, mixed> $r
     * @return array<string, mixed>
     */
    private function invoiceRow(array $r): array
    {
        $total = (int) $r['total_minor'];
        $open = in_array($r['status'], ['issued', 'partly_paid'], true);
        $due = $open ? max(0, $total - (int) $r['paid_minor'] - (int) $r['credited_minor']) : 0;
        $dueDate = self::day($r['due_date']);

        return [
            'kind' => $r['kind'] === 'sale_credit' ? 'credit_note' : 'invoice',
            'id' => (int) $r['id'],
            'number' => (string) ($r['number'] ?? ''),
            'status' => (string) $r['status'],
            'date' => self::day($r['issue_date']),
            'valid_until' => null,
            'due_date' => $dueDate,
            'overdue' => $open && $due > 0 && $dueDate !== null && $dueDate < gmdate('Y-m-d'),
            'currency' => (string) $r['currency'],
            'total_minor' => $total,
            'due_minor' => $due,
            'waiting' => $r['kind'] === 'sale' && $due > 0,
        ];
    }

    /**
     * @param array<string, mixed> $r
     * @return array<string, mixed>
     */
    private function bookingRow(array $r): array
    {
        return [
            'id' => (int) $r['id'],
            'status' => (string) $r['status'],
            'starts_at' => Interval::toApi(Interval::fromDb((string) $r['starts_at'])),
            'ends_at' => Interval::toApi(Interval::fromDb((string) $r['ends_at'])),
            'service_name' => LocalizedText::decode(is_string($r['service_name']) ? $r['service_name'] : ''),
            'staff_name' => is_string($r['staff_name']) ? $r['staff_name'] : null,
            'room_name' => is_string($r['room_name']) ? $r['room_name'] : null,
            'price_minor' => $r['price_minor'] !== null ? (int) $r['price_minor'] : null,
            'currency' => is_string($r['currency']) ? $r['currency'] : null,
            'checked_in' => $r['checked_in_at'] !== null,
        ];
    }

    private static function day(mixed $v): ?string
    {
        return is_string($v) && $v !== '' ? substr($v, 0, 10) : null;
    }

    private static function quantity(int $raw, int $decimals): string
    {
        if ($decimals <= 0) {
            return (string) $raw;
        }
        $s = str_pad((string) abs($raw), $decimals + 1, '0', STR_PAD_LEFT);

        return ($raw < 0 ? '-' : '') . substr($s, 0, -$decimals) . '.' . substr($s, -$decimals);
    }

    /** @param list<string> $values constants only, never input */
    private static function quoted(array $values): string
    {
        return implode(', ', array_map(static fn (string $v): string => "'" . str_replace("'", "''", $v) . "'", $values));
    }

    /**
     * @param list<int|string> $values
     * @return array{0: string, 1: array<string, int|string>}
     */
    private static function inList(string $prefix, array $values): array
    {
        $names = [];
        $bind = [];
        foreach ($values as $i => $v) {
            $names[] = ':' . $prefix . $i;
            $bind[':' . $prefix . $i] = $v;
        }

        return [implode(', ', $names), $bind];
    }
}
