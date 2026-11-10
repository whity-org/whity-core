<?php

declare(strict_types=1);

namespace Whity\Core\Usage;

use DateTimeImmutable;
use DateTimeZone;
use PDO;
use Whity\Core\Entitlement\EntitlementRegistry;
use Whity\Core\Entitlement\EntitlementService;
use Whity\Core\Hooks\HookManager;
use Whity\Core\Settings\SettingsRegistry;
use Whity\Core\Settings\SettingsService;
use Whity\Sdk\Hooks\Events;

/**
 * Customers a workspace served this month, against `customers.per_month`
 * (WHIT-932).
 *
 * ── What a customer is ────────────────────────────────────────────────────
 *
 * A person OUTSIDE the workspace's staff that the workspace did something for,
 * counted once per calendar month in the workspace's own time zone:
 *
 *  - somebody who sends one of the workspace's forms and is not its staff:
 *    a signed-in customer counts once however many forms they send;
 *  - somebody who sends a form without signing in: each such submission
 *    counts once, because two anonymous people cannot be told apart and no
 *    name or email is kept for the count;
 *  - somebody who joins as a customer through the workspace's own sign-up
 *    (`memberships.kind = 'customer'`, WHIT-915).
 *
 * Staff (`memberships.kind = 'member'`) sending an internal form are not
 * customers. Agents are never counted: they act for staff.
 *
 * ── Never refused ─────────────────────────────────────────────────────────
 *
 * Every customer is recorded and served. Passing the limit is written to the
 * log once a month and shown to the workspace's admins on Billing; nothing
 * turns a customer away. That is the business model's promise, and why this
 * key is on {@see EntitlementRegistry::WARN_ONLY}.
 *
 * Counting failures are swallowed: a counter must never break the form a
 * customer is sending.
 */
final class CustomerUsage
{
    public function __construct(
        private readonly PDO $db,
        private readonly EntitlementService $entitlements,
        private readonly SettingsService $settings,
    ) {
    }

    /** Count customers from the events that serve them. */
    public function subscribe(HookManager $hooks): void
    {
        $hooks->listen(Events::FORM_SUBMITTED, [$this, 'onFormSubmitted']);
        $hooks->listen(Events::MEMBERSHIP_CUSTOMER_JOINED, [$this, 'onCustomerJoined']);
    }

    /**
     * @param array<string, mixed> $data
     * @param array<string, mixed> $context
     * @return array<string, mixed>
     */
    public function onFormSubmitted(array $data, array $context = []): array
    {
        try {
            $tenantId = (int) ($data['tenant_id'] ?? 0);
            $submissionId = (int) ($data['id'] ?? 0);
            $actor = $data['actor_profile_id'] ?? null;
            if ($tenantId <= 0 || $submissionId <= 0) {
                return $data;
            }

            if ($actor === null) {
                $this->served($tenantId, 'submission:' . $submissionId);
            } elseif (!$this->isStaff($tenantId, (int) $actor)) {
                $this->served($tenantId, 'profile:' . (int) $actor);
            }
        } catch (\Throwable $e) {
            error_log('[customers] could not count a form submission: ' . $e->getMessage());
        }

        return $data;
    }

    /**
     * @param array<string, mixed> $data
     * @param array<string, mixed> $context
     * @return array<string, mixed>
     */
    public function onCustomerJoined(array $data, array $context = []): array
    {
        try {
            $tenantId = (int) ($data['tenant_id'] ?? 0);
            $profileId = (int) ($data['profile_id'] ?? 0);
            if ($tenantId > 0 && $profileId > 0) {
                $this->served($tenantId, 'profile:' . $profileId);
            }
        } catch (\Throwable $e) {
            error_log('[customers] could not count a customer joining: ' . $e->getMessage());
        }

        return $data;
    }

    /**
     * Record that the workspace served this customer this month.
     *
     * @return bool True when this is the customer's first time this month.
     */
    public function served(int $tenantId, string $customerRef, ?DateTimeImmutable $now = null): bool
    {
        $period = $this->period($tenantId, $now ?? new DateTimeImmutable());

        $stmt = $this->db->prepare(
            'INSERT INTO tenant_monthly_customers (tenant_id, period, customer_ref, first_seen_at)
             VALUES (:tenant_id, :period, :ref, NOW())
             ON CONFLICT (tenant_id, period, customer_ref) DO NOTHING'
        );
        $stmt->execute([':tenant_id' => $tenantId, ':period' => $period, ':ref' => substr($customerRef, 0, 80)]);
        $isNew = $stmt->rowCount() > 0;

        if ($isNew) {
            $limit = $this->entitlements->limit($tenantId, EntitlementRegistry::CUSTOMERS_PER_MONTH);
            // Logged only by the customer who crosses the line, so a workspace
            // far past its limit is one line a month, not one per customer.
            if ($limit !== EntitlementRegistry::UNLIMITED && $this->countFor($tenantId, $period) === $limit + 1) {
                error_log(sprintf('[customers] tenant %d passed customers.per_month (limit %d) in %s', $tenantId, $limit, $period));
            }
        }

        return $isNew;
    }

    /** Distinct customers served in the month containing `$now`. */
    public function count(int $tenantId, ?DateTimeImmutable $now = null): int
    {
        return $this->countFor($tenantId, $this->period($tenantId, $now ?? new DateTimeImmutable()));
    }

    /** When the month containing `$now` ends, in the workspace's own zone. */
    public function resetsAt(int $tenantId, ?DateTimeImmutable $now = null): string
    {
        return ($now ?? new DateTimeImmutable())
            ->setTimezone($this->zoneFor($tenantId))
            ->modify('first day of next month midnight')
            ->format(DATE_ATOM);
    }

    private function countFor(int $tenantId, string $period): int
    {
        $stmt = $this->db->prepare(
            'SELECT COUNT(*) FROM tenant_monthly_customers WHERE tenant_id = :tenant_id AND period = :period'
        );
        $stmt->execute([':tenant_id' => $tenantId, ':period' => $period]);

        return (int) $stmt->fetchColumn();
    }

    private function isStaff(int $tenantId, int $profileId): bool
    {
        $stmt = $this->db->prepare(
            "SELECT 1 FROM memberships
              WHERE tenant_id = :tenant_id AND profile_id = :profile_id AND kind = 'member'
              LIMIT 1"
        );
        $stmt->execute([':tenant_id' => $tenantId, ':profile_id' => $profileId]);

        return $stmt->fetchColumn() !== false;
    }

    private function period(int $tenantId, DateTimeImmutable $now): string
    {
        return $now->setTimezone($this->zoneFor($tenantId))->format('Y-m');
    }

    /** The workspace's zone, as {@see \Whity\Core\Entitlement\MeterService} reads it. */
    private function zoneFor(int $tenantId): DateTimeZone
    {
        $value = $this->settings->effective($tenantId)[SettingsRegistry::TIMEZONE] ?? null;
        if (!is_string($value) || $value === '') {
            $value = 'UTC';
        }

        try {
            return new DateTimeZone($value);
        } catch (\Exception) {
            return new DateTimeZone('UTC');
        }
    }
}
