<?php

declare(strict_types=1);

namespace Whity\Core\Calendar;

use DateTimeImmutable;
use PDO;
use Whity\Core\i18n\LocalizedText;
use Whity\Core\Security\PublicToken;

/**
 * Forms to fill before a visit (WHIT-1443, design section 39 §7.2, AP.20).
 *
 * A service names forms (section 22): on booking or before the visit, for a
 * customer's first visit or every one, required or not. Each booking that
 * needs one gets a link to the form's public page carrying a token; a
 * submission sent with it records the booking and the customer. The desk sees
 * done, not done or not needed. A missing form never blocks check-in.
 *
 * Tenant first everywhere; a token is the key only on the public form page.
 */
final class IntakeForms
{
    public const ON_BOOKING = 'on_booking';
    public const BEFORE_VISIT = 'before_visit';
    public const NEW_CUSTOMERS = 'new';
    public const EVERY_VISIT = 'every';

    public const DONE = 'done';
    public const NOT_DONE = 'not_done';
    public const NOT_NEEDED = 'not_needed';

    public function __construct(
        private readonly PDO $db,
        /** The public form page's address, `https://app.example.com/f`. */
        private readonly string $formUrlBase,
    ) {
    }

    // ── on the service ──────────────────────────────────────────────────

    /**
     * The forms a service asks for, in order.
     *
     * @return list<array{form_id: int, name: string, names: array<string, string>, fill_when: string, who: string, required: bool, public: bool}>
     */
    public function forService(int $tenantId, int $serviceId): array
    {
        return $this->forServices($tenantId, [$serviceId])[$serviceId] ?? [];
    }

    /**
     * Replace what a service asks for. Each form must be this workspace's.
     *
     * @param list<array<string, mixed>> $rows
     */
    public function replaceForService(int $tenantId, int $serviceId, array $rows): void
    {
        $clean = [];
        foreach ($rows as $i => $row) {
            $formId = is_int($row['form_id'] ?? null) ? $row['form_id'] : throw new CalendarRejectedException('Each form needs a form_id.', 422);
            $exists = $this->db->prepare('SELECT 1 FROM forms WHERE tenant_id = :tenant_id AND id = :id');
            $exists->execute([':tenant_id' => $tenantId, ':id' => $formId]);
            if ($exists->fetchColumn() === false) {
                throw CalendarRejectedException::notFound('Form');
            }
            $when = $row['fill_when'] ?? self::BEFORE_VISIT;
            $who = $row['who'] ?? self::EVERY_VISIT;
            if (!in_array($when, [self::ON_BOOKING, self::BEFORE_VISIT], true) || !in_array($who, [self::NEW_CUSTOMERS, self::EVERY_VISIT], true)) {
                throw new CalendarRejectedException('fill_when is on_booking or before_visit, and who is new or every.', 422);
            }
            $clean[$formId] = ['when' => $when, 'who' => $who, 'required' => ($row['required'] ?? true) !== false, 'sort' => $i];
        }
        if (count($clean) > 8) {
            throw new CalendarRejectedException('A service can ask for up to 8 forms.', 422);
        }
        $this->db->prepare('DELETE FROM calendar_service_forms WHERE tenant_id = :tenant_id AND service_id = :service_id')
            ->execute([':tenant_id' => $tenantId, ':service_id' => $serviceId]);
        $insert = $this->db->prepare(
            'INSERT INTO calendar_service_forms (tenant_id, service_id, form_id, fill_when, who, is_required, sort_order)
             VALUES (:tenant_id, :service_id, :form_id, :fill_when, :who, :required, :sort)'
        );
        foreach ($clean as $formId => $row) {
            $insert->bindValue(':tenant_id', $tenantId, PDO::PARAM_INT);
            $insert->bindValue(':service_id', $serviceId, PDO::PARAM_INT);
            $insert->bindValue(':form_id', $formId, PDO::PARAM_INT);
            $insert->bindValue(':fill_when', $row['when']);
            $insert->bindValue(':who', $row['who']);
            $insert->bindValue(':required', $row['required'], PDO::PARAM_BOOL);
            $insert->bindValue(':sort', $row['sort'], PDO::PARAM_INT);
            $insert->execute();
        }
    }

    // ── on the booking ──────────────────────────────────────────────────

    /**
     * For each booking: `done` when every form it needs was sent for it,
     * `not_done` when one is missing, `not_needed` when it needs none, and
     * each form with its own state. Read in a few queries for a whole day.
     *
     * @param list<array<string, mixed>> $bookings as {@see BookingRepository::toApi()} gives them
     * @return array<int, array{status: string, forms: list<array{form_id: int, name: string, names: array<string, string>, required: bool, done: bool, submission_id: ?int, submitted_at: ?string, fill_when: string}>}>
     */
    public function statusFor(int $tenantId, array $bookings): array
    {
        $out = [];
        $serviceIds = array_values(array_unique(array_map(static fn (array $b): int => (int) $b['service_id'], $bookings)));
        $asked = $serviceIds === [] ? [] : $this->forServices($tenantId, $serviceIds);
        $bookingIds = array_map(static fn (array $b): int => (int) $b['id'], $bookings);
        $sent = $this->submissionsFor($tenantId, $bookingIds);
        foreach ($bookings as $b) {
            $id = (int) $b['id'];
            $forms = [];
            foreach ($asked[(int) $b['service_id']] ?? [] as $form) {
                if ($form['who'] === self::NEW_CUSTOMERS && !$this->isFirstVisit($tenantId, $b)) {
                    continue;
                }
                $submission = $sent[$id][$form['form_id']] ?? null;
                $forms[] = [
                    'form_id' => $form['form_id'],
                    'name' => $form['name'],
                    'names' => $form['names'],
                    'required' => $form['required'],
                    'fill_when' => $form['fill_when'],
                    'done' => $submission !== null,
                    'submission_id' => $submission['id'] ?? null,
                    'submitted_at' => $submission['submitted_at'] ?? null,
                ];
            }
            $missing = array_filter($forms, static fn (array $f): bool => $f['required'] && !$f['done']);
            $out[$id] = [
                'status' => $forms === [] ? self::NOT_NEEDED : ($missing === [] ? self::DONE : self::NOT_DONE),
                'forms' => $forms,
            ];
        }

        return $out;
    }

    /**
     * A fresh link to fill a form for a booking: the form's public page with
     * a token that records the booking. Null when the form has no public link.
     */
    public function linkFor(int $tenantId, int $bookingId, int $formId, DateTimeImmutable $now): ?string
    {
        $stmt = $this->db->prepare('SELECT public_slug, public_enabled FROM forms WHERE tenant_id = :tenant_id AND id = :id');
        $stmt->execute([':tenant_id' => $tenantId, ':id' => $formId]);
        $form = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!is_array($form) || !is_string($form['public_slug']) || $form['public_slug'] === '' || !\Whity\Core\Db\DbBool::of($form['public_enabled']) || $this->formUrlBase === '') {
            return null;
        }
        $token = PublicToken::make();
        $insert = $this->db->prepare(
            'INSERT INTO calendar_intake_links (tenant_id, booking_id, form_id, token_hash, created_at)
             VALUES (:tenant_id, :booking_id, :form_id, :hash, :now)'
        );
        $insert->execute([':tenant_id' => $tenantId, ':booking_id' => $bookingId, ':form_id' => $formId, ':hash' => PublicToken::hash($token), ':now' => Interval::toDb($now)]);

        return rtrim($this->formUrlBase, '/') . '/' . rawurlencode($form['public_slug']) . '?intake=' . $token;
    }

    /**
     * The booking a token on a form's public page stands for, with what the
     * customer gave when they booked, for the page to fill in. Null when the
     * token is not one for this form.
     *
     * @return array{tenant_id: int, booking_id: int, contact_id: ?int, name: string, email: string, phone: string}|null
     */
    public function resolve(string $token, int $tenantId, int $formId): ?array
    {
        if (preg_match('/^[a-f0-9]{64}$/', $token) !== 1) {
            return null;
        }
        $stmt = $this->db->prepare(
            'SELECT l.booking_id, b.contact_id, b.customer_name, b.customer_email, b.customer_phone
               FROM calendar_intake_links l
               JOIN calendar_bookings b ON b.id = l.booking_id AND b.tenant_id = l.tenant_id
              WHERE l.token_hash = :hash AND l.tenant_id = :tenant_id AND l.form_id = :form_id'
        );
        $stmt->execute([':hash' => PublicToken::hash($token), ':tenant_id' => $tenantId, ':form_id' => $formId]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!is_array($row)) {
            return null;
        }

        return [
            'tenant_id' => $tenantId,
            'booking_id' => (int) $row['booking_id'],
            'contact_id' => $row['contact_id'] !== null ? (int) $row['contact_id'] : null,
            'name' => (string) $row['customer_name'],
            'email' => (string) $row['customer_email'],
            'phone' => (string) $row['customer_phone'],
        ];
    }

    /** Record that a submission was sent for a booking, from the token's link. */
    public function attach(int $tenantId, int $submissionId, int $bookingId, ?int $contactId): void
    {
        $stmt = $this->db->prepare('UPDATE form_submissions SET booking_id = :booking_id, contact_id = :contact_id WHERE tenant_id = :tenant_id AND id = :id');
        $stmt->bindValue(':booking_id', $bookingId, PDO::PARAM_INT);
        $stmt->bindValue(':contact_id', $contactId, $contactId === null ? PDO::PARAM_NULL : PDO::PARAM_INT);
        $stmt->bindValue(':tenant_id', $tenantId, PDO::PARAM_INT);
        $stmt->bindValue(':id', $submissionId, PDO::PARAM_INT);
        $stmt->execute();
    }

    /**
     * The submissions sent for a booking, newest first (the visit record).
     *
     * @return list<array{id: int, form_id: int, name: string, names: array<string, string>, submitted_at: string}>
     */
    public function submissionsOf(int $tenantId, int $bookingId): array
    {
        $stmt = $this->db->prepare(
            'SELECT s.id, s.form_id, f.name, s.submitted_at FROM form_submissions s
               JOIN forms f ON f.id = s.form_id AND f.tenant_id = s.tenant_id
              WHERE s.tenant_id = :tenant_id AND s.booking_id = :booking_id AND s.withdrawn_at IS NULL
              ORDER BY s.submitted_at DESC, s.id DESC'
        );
        $stmt->execute([':tenant_id' => $tenantId, ':booking_id' => $bookingId]);

        return array_values(array_map(static fn (array $r): array => [
            'id' => (int) $r['id'],
            'form_id' => (int) $r['form_id'],
            'name' => self::formName((string) $r['name']),
            'names' => self::formNames((string) $r['name']),
            'submitted_at' => Interval::toApi(Interval::fromDb((string) $r['submitted_at'])),
        ], $stmt->fetchAll(PDO::FETCH_ASSOC)));
    }

    // ── inside ──────────────────────────────────────────────────────────

    /**
     * @param list<int> $serviceIds
     * @return array<int, list<array{form_id: int, name: string, names: array<string, string>, fill_when: string, who: string, required: bool, public: bool}>>
     */
    private function forServices(int $tenantId, array $serviceIds): array
    {
        $marks = implode(',', array_fill(0, count($serviceIds), '?'));
        $stmt = $this->db->prepare(
            "SELECT sf.service_id, sf.form_id, sf.fill_when, sf.who, sf.is_required, f.name, f.public_enabled
               FROM calendar_service_forms sf
               JOIN forms f ON f.id = sf.form_id AND f.tenant_id = sf.tenant_id
              WHERE sf.tenant_id = ? AND sf.service_id IN ({$marks})
              ORDER BY sf.service_id, sf.sort_order, sf.id"
        );
        $stmt->execute([$tenantId, ...$serviceIds]);
        $out = [];
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $r) {
            $out[(int) $r['service_id']][] = [
                'form_id' => (int) $r['form_id'],
                'name' => self::formName((string) $r['name']),
                'names' => self::formNames((string) $r['name']),
                'fill_when' => (string) $r['fill_when'],
                'who' => (string) $r['who'],
                'required' => \Whity\Core\Db\DbBool::of($r['is_required']),
                'public' => \Whity\Core\Db\DbBool::of($r['public_enabled']),
            ];
        }

        return $out;
    }

    /**
     * @param list<int> $bookingIds
     * @return array<int, array<int, array{id: int, submitted_at: string}>> by booking, then form
     */
    private function submissionsFor(int $tenantId, array $bookingIds): array
    {
        if ($bookingIds === []) {
            return [];
        }
        $marks = implode(',', array_fill(0, count($bookingIds), '?'));
        $stmt = $this->db->prepare(
            "SELECT id, booking_id, form_id, submitted_at FROM form_submissions
              WHERE tenant_id = ? AND booking_id IN ({$marks}) AND withdrawn_at IS NULL
              ORDER BY submitted_at"
        );
        $stmt->execute([$tenantId, ...$bookingIds]);
        $out = [];
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $r) {
            $out[(int) $r['booking_id']][(int) $r['form_id']] = ['id' => (int) $r['id'], 'submitted_at' => Interval::toApi(Interval::fromDb((string) $r['submitted_at']))];
        }

        return $out;
    }

    /**
     * A customer's first visit: no earlier booking of theirs that went ahead.
     *
     * @param array<string, mixed> $booking
     */
    private function isFirstVisit(int $tenantId, array $booking): bool
    {
        $contactId = $booking['contact_id'] ?? null;
        if (!is_int($contactId)) {
            return true;
        }
        $stmt = $this->db->prepare(
            "SELECT 1 FROM calendar_bookings
              WHERE tenant_id = :tenant_id AND contact_id = :contact_id AND id <> :id AND starts_at < :starts
                AND status = 'confirmed' AND (attendance IS NULL OR attendance <> 'no_show')"
        );
        $stmt->execute([':tenant_id' => $tenantId, ':contact_id' => $contactId, ':id' => (int) $booking['id'], ':starts' => Interval::toDb(Interval::fromApi((string) $booking['starts_at']) ?? new DateTimeImmutable())]);

        return $stmt->fetchColumn() === false;
    }

    /**
     * A form's name in every language it has.
     *
     * @return array<string, string>
     */
    private static function formNames(string $raw): array
    {
        $decoded = json_decode($raw, true);

        return is_array($decoded) ? LocalizedText::decode($raw, languages: LocalizedText::BILINGUAL) : ['en' => $raw];
    }

    /** A form's name is a localised label or plain text; its English (or first) wording. */
    private static function formName(string $raw): string
    {
        $decoded = json_decode($raw, true);
        if (is_array($decoded)) {
            $names = LocalizedText::decode($raw, languages: LocalizedText::BILINGUAL);

            return (string) ($names['en'] ?? reset($names) ?: $raw);
        }

        return $raw;
    }
}
