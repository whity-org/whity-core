<?php

declare(strict_types=1);

namespace Tests\Core\Billing;

use PDO;
use PHPUnit\Framework\TestCase;
use Tests\Support\SchemaFromMigrations;
use Whity\Api\CashPaymentsApiHandler;
use Whity\Core\Audit\AuditLogger;
use Whity\Core\Billing\Cash\CashPayments;
use Whity\Core\Billing\Cash\CashRemindersJob;
use Whity\Core\Mail\EmailContent;
use Whity\Core\Plugin\Workspace\CodePluginActivation;
use Whity\Core\Request;
use Whity\Core\Tenant\TenantContext;

/**
 * WHIT-1153: the operator records cash payments for paid plugin plans; they
 * cover the workspace like a subscription, then 7 days of grace, then
 * read-only; they show in history, remind before the end, and can be voided.
 */
final class CashPaymentsRealEngineTest extends TestCase
{
    private PDO $pdo;
    private int $now;
    private CashPayments $cash;

    protected function setUp(): void
    {
        TenantContext::reset();
        $this->pdo = SchemaFromMigrations::make(true);
        $this->pdo->exec("INSERT OR IGNORE INTO tenants (id, name) VALUES (0, 'system')");
        $this->pdo->exec("INSERT INTO tenants (id, name) VALUES (5, 'example-barbers')");
        $this->pdo->exec("INSERT INTO plans (id, plan_key, name, is_active, sort_order, is_addon, created_at, updated_at) VALUES
            (40, 'plugin.salons.monthly', 'Whity for Salons', true, 1, true, CURRENT_TIMESTAMP, CURRENT_TIMESTAMP),
            (41, 'plugin.salons.half_year', 'Whity for Salons', true, 2, true, CURRENT_TIMESTAMP, CURRENT_TIMESTAMP),
            (42, 'team', 'Team', true, 3, false, CURRENT_TIMESTAMP, CURRENT_TIMESTAMP)");
        $this->pdo->exec("INSERT INTO plan_prices (plan_id, currency, unit_amount, billing_period, is_per_seat, is_active, created_at, updated_at) VALUES
            (40, 'JOD', 15000, 'month', true, true, CURRENT_TIMESTAMP, CURRENT_TIMESTAMP),
            (41, 'JOD', 60000, 'half_year', true, true, CURRENT_TIMESTAMP, CURRENT_TIMESTAMP),
            (42, 'JOD', 49000, 'month', false, true, CURRENT_TIMESTAMP, CURRENT_TIMESTAMP)");
        $this->now = (int) strtotime('2026-11-16 10:00:00 UTC');
        $this->cash = new CashPayments($this->pdo, fn (): int => $this->now);
    }

    protected function tearDown(): void
    {
        TenantContext::reset();
    }

    public function testAPaymentCoversItsPeriodAndTheNextStartsWhereItEnds(): void
    {
        $first = $this->cash->record(5, 'plugin.salons.monthly', 3, 45000, 'JOD', 'R-001', 'Paid at the counter', '2026-11-16', null);
        $this->assertSame(['2026-11-16 00:00:00', '2026-12-16 00:00:00', 3], [$first['period_start'], $first['period_end'], $first['quantity']]);

        $second = $this->cash->record(5, 'plugin.salons.half_year', 3, 180000, 'JOD', 'R-002', null, '2026-12-01', null);
        $this->assertSame(['2026-12-16 00:00:00', '2027-06-16 00:00:00'], [$second['period_start'], $second['period_end']], 'Paid early: starts when the last one ends');

        $this->assertSame(['active' => true, 'until' => '2027-06-16 00:00:00', 'quantity' => 3], $this->cash->coverOf(5, 'plugin.salons'));
    }

    public function testOnlyAPaidPluginPlanAndOnlyAReceiptOnce(): void
    {
        foreach ([
            ['team', 'R-1'],
            ['plugin.salons.monthly', ''],
        ] as [$plan, $receipt]) {
            try {
                $this->cash->record(5, $plan, 1, 15000, 'JOD', $receipt, null, '2026-11-16', null);
                $this->fail("{$plan} / '{$receipt}' should be refused");
            } catch (\InvalidArgumentException) {
                $this->addToAssertionCount(1);
            }
        }
        $this->cash->record(5, 'plugin.salons.monthly', 1, 15000, 'JOD', 'R-7', null, '2026-11-16', null);
        $this->expectException(\InvalidArgumentException::class);
        $this->cash->record(5, 'plugin.salons.monthly', 1, 15000, 'JOD', 'R-7', null, '2026-11-17', null);
    }

    public function testItGatesLikeASubscriptionThenGraceThenReadOnly(): void
    {
        $this->cash->record(5, 'plugin.salons.monthly', 2, 30000, 'JOD', 'R-010', null, '2026-11-16', null);
        $activation = new CodePluginActivation($this->pdo, fn (): int => $this->now);
        $activation->usePaidAccess(static fn (): array => ['Salons' => ['entitlement' => 'salons.access', 'product' => 'plugin.salons', 'trialDays' => 14]]);
        $activation->useEntitlements(static fn (): bool => false);
        $activation->useCashCover(fn (int $t, string $p): ?array => $this->cash->coverOf($t, $p));
        $activation->setEnabled(5, 'Salons', true);

        $this->assertTrue($activation->mayTurnOn('Salons', 5));
        $this->assertSame(['on', 'cash'], array_slice(array_values($activation->stateFor('Salons', 5)), 0, 2));

        $this->now = (int) strtotime('2026-12-20 10:00:00 UTC');
        $activation->resetRequest();
        $state = $activation->stateFor('Salons', 5);
        $this->assertSame(['on', 'grace', '2026-12-23 00:00:00'], [$state['state'], $state['reason'], $state['graceUntil']]);

        $this->now = (int) strtotime('2026-12-24 10:00:00 UTC');
        $activation->resetRequest();
        $this->assertSame('read_only', $activation->stateFor('Salons', 5)['state']);
    }

    public function testVoidingNeedsAReasonAndStopsTheCover(): void
    {
        $payment = $this->cash->record(5, 'plugin.salons.monthly', 1, 15000, 'JOD', 'R-020', null, '2026-11-16', null);
        try {
            $this->cash->void((int) $payment['id'], ' ', null);
            $this->fail('A void needs a reason');
        } catch (\InvalidArgumentException) {
            $this->addToAssertionCount(1);
        }
        $voided = $this->cash->void((int) $payment['id'], 'Recorded for the wrong workspace', null);
        $this->assertSame('Recorded for the wrong workspace', $voided['void_reason']);
        $this->assertNull($this->cash->coverOf(5, 'plugin.salons'));
        $this->assertSame('R-020', $this->cash->forWorkspace(5)[0]['receipt_number'], 'Still in the history');
    }

    public function testTheLastPaymentRemindsOnceAWeekBeforeItEnds(): void
    {
        $this->cash->record(5, 'plugin.salons.monthly', 1, 15000, 'JOD', 'R-030', null, '2026-11-16', null);
        $sent = [];
        $job = new CashRemindersJob(
            $this->cash,
            static function (int $tenantId, string $subject, EmailContent $content) use (&$sent): int {
                $sent[] = [$tenantId, $subject];

                return 1;
            },
            static fn (): string => 'en',
            static fn (string $plan): string => 'Whity for Salons',
            'https://app.example.test/billing',
        );

        $this->assertSame(['reminded' => 0], $job->handle([]), 'Not yet: a month away');
        $this->now = (int) strtotime('2026-12-12 10:00:00 UTC');
        $this->assertSame(['reminded' => 1], $job->handle([]));
        $this->assertSame([[5, 'Your Whity for Salons ends on 2026-12-16']], $sent);
        $this->assertSame(['reminded' => 0], $job->handle([]), 'Once');
    }

    public function testOnlyTheOperatorRecordsAndEveryRecordIsAudited(): void
    {
        $api = new CashPaymentsApiHandler($this->cash, new AuditLogger($this->pdo));
        $body = ['workspace' => 5, 'planKey' => 'plugin.salons.monthly', 'quantity' => 2, 'amount' => '30', 'receiptNumber' => 'R-040', 'paidOn' => '2026-11-16'];

        TenantContext::setTenantId(5);
        $this->assertSame(403, $api->record($this->request($body))->getStatusCode());

        TenantContext::reset();
        TenantContext::setTenantId(0);
        $created = $api->record($this->request($body));
        $this->assertSame(201, $created->getStatusCode());
        $this->assertStringContainsString('"amount_minor":30000', $created->getBody(), 'JOD has three decimals');
        $count = $this->pdo->query("SELECT COUNT(*) FROM audit_log WHERE action = 'billing.cash_payment_recorded' AND tenant_id = 5");
        $this->assertNotFalse($count);
        $this->assertSame(1, (int) $count->fetchColumn());

        $this->assertSame(422, $api->record($this->request(['amount' => '1.2345'] + $body))->getStatusCode());
        $this->assertSame(1500, CashPaymentsApiHandler::minor('15', 'USD'));
    }

    /** @param array<string, mixed> $body */
    private function request(array $body): Request
    {
        $request = new Request('POST', '/api/billing/cash-payments', ['Content-Type' => 'application/json'], (string) json_encode($body));
        $request->user = (object) ['profile_id' => null];

        return $request;
    }
}
