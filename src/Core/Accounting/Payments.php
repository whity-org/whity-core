<?php

declare(strict_types=1);

namespace Whity\Core\Accounting;

use Whity\Core\Db\DbBool;
use Whity\Core\Hooks\HookManager;
use Whity\Core\Money\Currency;
use Whity\Core\Money\MoneyException;
use Whity\Sdk\Hooks\Events;
use Whity\Sdk\Sql\SequenceAllocator;

/**
 * Money received, what it settles, cheques, and transfers between the
 * workspace's own accounts (WHIT-1318 A2b, design section 32 §5.4, §5.5,
 * register AC.13, AC.14, AC.17).
 *
 * A PAYMENT RECEIVED posts one entry the moment it is recorded:
 *
 *     Dr Bank, Cash, Cheques to collect or Money at payment providers
 *     Cr Customers owe us                                    (the customer)
 *
 * and, when a payment provider kept a fee, Dr Bank and payment fees / Cr
 * Money at payment providers. It then settles invoices through
 * `acc_allocations`; what it doesn't settle stays on Customers owe us as the
 * customer's credit, to allocate later. Allocating posts nothing: the
 * customer's balance already moved.
 *
 * CHEQUES (Q7). A cheque dated later than the day it is received waits on
 * Cheques to collect. Depositing it posts Cheques to collect → Bank. A cheque
 * that bounces reverses its entries, releases what it settled (the invoices
 * reopen), and posts the bank's fee when there is one.
 *
 * TRANSFERS move money between the workspace's own accounts: cash to bank,
 * or a payment provider's payout to the bank (Whity Pay's `recordTransfer`).
 *
 * `external_ref` makes a payment idempotent: Whity Pay passes its own payment
 * id, so a webhook delivered twice records once. Until multi-currency (A7) a
 * payment is in the books' base currency.
 *
 * TENANT-OWNED: every statement binds `tenant_id`.
 */
final class Payments
{
    public const METHODS = ['cash', 'bank_transfer', 'cliq', 'card', 'cheque', 'whity_pay', 'other'];
    public const DIRECTIONS = ['in', 'out', 'transfer'];

    private const COLUMNS = 'p.id, p.direction, p.number, p.contact_id, p.paid_on, p.amount_minor, p.currency, p.method, p.account_id,
        p.from_account_id, p.reference, p.cheque_number, p.cheque_bank, p.cheque_due_on, p.cheque_status, p.fee_minor, p.status,
        p.notes, p.entry_id, p.deposit_entry_id, p.bounce_entry_id, p.external_ref, p.created_by_profile_id, p.agent_id,
        p.on_behalf_of_profile_id, p.owner_plugin, p.created_at';

    /** @param (\Closure(): \DateTimeImmutable)|null $clock */
    public function __construct(
        private readonly AccountingDb $db,
        private readonly AccountingWorkspace $workspace,
        private readonly Ledger $ledger,
        private readonly Invoices $invoices,
        private readonly SequenceAllocator $sequences,
        private readonly ?HookManager $hooks = null,
        private readonly ?\Closure $clock = null,
    ) {
    }

    // ── receiving ────────────────────────────────────────────────────────

    /**
     * Records money a customer paid, posts it, and settles the invoices named.
     *
     * @param array<string, mixed> $data `contact_id`, `paid_on`, `amount_minor` or `amount` ("12.500"), `method`,
     *     `account_id` (where it went, instead of the method's account), `reference`, `cheque_number`, `cheque_bank`,
     *     `cheque_due_on`, `fee_minor`, `notes`, `allocations` (`[{invoice_id, amount_minor?}]`) or `invoice_id`
     * @param array{agent_id?: ?int, on_behalf_of?: ?int, external_ref?: ?string, owner_plugin?: ?string} $meta
     * @return array{payment: array<string, mixed>, repeated: bool}
     */
    public function receive(int $tenantId, ?int $actor, array $data, array $meta = []): array
    {
        $this->workspace->ensure($tenantId);
        $external = self::externalRef($meta['external_ref'] ?? null);
        if ($external !== null && ($done = $this->idByExternal($tenantId, $external)) !== null) {
            return ['payment' => $this->get($tenantId, $done), 'repeated' => true];
        }
        $currency = $this->workspace->books($tenantId)['base_currency'];
        $contactId = $this->customer($tenantId, $data['contact_id'] ?? null);
        $date = isset($data['paid_on']) && $data['paid_on'] !== '' ? Periods::date($data['paid_on'], 'paid_on') : $this->today();
        $amount = $this->amount($data, $currency, 'amount');
        $method = $data['method'] ?? 'bank_transfer';
        if (!in_array($method, self::METHODS, true)) {
            throw AccountingRejectedException::field('method', 'method is one of ' . implode(', ', self::METHODS));
        }
        $fee = $data['fee_minor'] ?? 0;
        if (!is_int($fee) || $fee < 0 || $fee >= $amount) {
            throw AccountingRejectedException::field('fee_minor', 'fee_minor is what the provider kept, in minor units, from 0 to less than the amount');
        }
        if ($fee > 0 && !in_array($method, ['card', 'whity_pay'], true)) {
            throw AccountingRejectedException::field('fee_minor', 'Only a card or Whity Pay payment has a provider\'s fee; record a bank\'s fee as its own entry');
        }
        $cheque = null;
        if ($method === 'cheque') {
            $due = isset($data['cheque_due_on']) && $data['cheque_due_on'] !== '' ? Periods::date($data['cheque_due_on'], 'cheque_due_on') : $date;
            $cheque = [
                'number' => self::text($data['cheque_number'] ?? null, 60, 'cheque_number', true),
                'bank' => self::text($data['cheque_bank'] ?? null, 120, 'cheque_bank', false),
                'due_on' => $due,
                'waits' => $due > $date,
            ];
        }
        $accountId = isset($data['account_id'])
            ? $this->moneyAccount($tenantId, $data['account_id'], 'account_id')
            : $this->workspace->roleAccountId($tenantId, match (true) {
                $method === 'cash' => 'cash',
                $cheque !== null && $cheque['waits'] => 'cheques_to_collect',
                in_array($method, ['card', 'whity_pay'], true) => 'provider_clearing',
                default => 'bank',
            });
        $allocations = $this->wantedAllocations($data);
        $byInvoiceId = isset($data['invoice_id']);
        $reference = self::text($data['reference'] ?? null, 200, 'reference', false) ?? '';
        $notes = is_string($data['notes'] ?? null) ? mb_substr(trim($data['notes']), 0, 2000) : '';

        try {
            $id = $this->db->transaction(function () use ($tenantId, $actor, $contactId, $date, $amount, $currency, $method, $accountId, $reference,
                $cheque, $fee, $notes, $external, $meta, $allocations, $byInvoiceId): int {
                $year = substr($date, 0, 4);
                $number = sprintf('RCT-%s-%04d', $year, $this->sequences->next($tenantId, 'acc_payments:in:' . $year));
                $id = $this->db->insert(
                    'INSERT INTO acc_payments (tenant_id, direction, number, contact_id, paid_on, amount_minor, currency, method, account_id,
                            reference, cheque_number, cheque_bank, cheque_due_on, cheque_status, fee_minor, status, notes, external_ref,
                            created_by_profile_id, agent_id, on_behalf_of_profile_id, owner_plugin)
                     VALUES (:tenant_id, \'in\', :number, :contact_id, :paid_on, :amount, :currency, :method, :account_id,
                            :reference, :cheque_number, :cheque_bank, :cheque_due_on, :cheque_status, :fee, \'posted\', :notes, :external,
                            :actor, :agent, :on_behalf, :owner)',
                    [
                        ':tenant_id' => $tenantId, ':number' => $number, ':contact_id' => $contactId, ':paid_on' => $date, ':amount' => $amount,
                        ':currency' => $currency, ':method' => $method, ':account_id' => $accountId, ':reference' => $reference,
                        ':cheque_number' => $cheque['number'] ?? null, ':cheque_bank' => $cheque['bank'] ?? null,
                        ':cheque_due_on' => $cheque['due_on'] ?? null,
                        ':cheque_status' => $cheque === null ? null : ($cheque['waits'] ? 'waiting' : 'cleared'),
                        ':fee' => $fee, ':notes' => $notes, ':external' => $external, ':actor' => $actor,
                        ':agent' => AccountingDb::int($meta['agent_id'] ?? null), ':on_behalf' => AccountingDb::int($meta['on_behalf_of'] ?? null),
                        ':owner' => is_string($meta['owner_plugin'] ?? null) ? $meta['owner_plugin'] : null,
                    ]
                );
                $receivable = $this->workspace->roleAccountId($tenantId, 'receivable');
                $name = $this->contactName($tenantId, $contactId);
                $lines = [
                    ['account_id' => $accountId, 'debit_minor' => $amount, 'contact_id' => $contactId, 'description' => $reference !== '' ? $reference : $name],
                    ['account_id' => $receivable, 'credit_minor' => $amount, 'contact_id' => $contactId, 'description' => $name],
                ];
                if ($fee > 0) {
                    $lines[] = ['account_id' => $this->workspace->roleAccountId($tenantId, 'payment_fees'), 'debit_minor' => $fee, 'description' => 'Provider fee'];
                    $lines[] = ['account_id' => $accountId, 'credit_minor' => $fee, 'description' => 'Provider fee'];
                }
                $posted = $this->ledger->post($tenantId, $actor, [
                    'journal' => $method === 'cash' ? 'CSH' : 'BNK1',
                    'date' => $date,
                    'memo' => 'Payment ' . $number . ' from ' . $name,
                    'source_type' => 'payment',
                    'source_id' => $id,
                    'idempotency_key' => 'payment:' . $id,
                    'agent_id' => AccountingDb::int($meta['agent_id'] ?? null),
                    'on_behalf_of' => AccountingDb::int($meta['on_behalf_of'] ?? null),
                    'lines' => $lines,
                ]);
                $this->db->run(
                    'UPDATE acc_payments SET entry_id = :entry_id WHERE tenant_id = :tenant_id AND id = :id',
                    [':entry_id' => (int) $posted['entry']['id'], ':tenant_id' => $tenantId, ':id' => $id]
                );
                $this->notify(Events::ACCOUNTING_PAYMENT_RECORDED, $tenantId, $id, $actor);
                foreach ($allocations as $i => [$invoiceId, $share]) {
                    $this->settle($tenantId, $id, $invoiceId, $share, $actor, $byInvoiceId ? 'invoice_id' : 'allocations[' . $i . ']');
                }

                return $id;
            });
        } catch (\PDOException $e) {
            if ($external !== null && AccountingDb::isUniqueViolation($e) && ($done = $this->idByExternal($tenantId, $external)) !== null) {
                return ['payment' => $this->get($tenantId, $done), 'repeated' => true];
            }
            throw $e;
        }

        return ['payment' => $this->get($tenantId, $id), 'repeated' => false];
    }

    /**
     * Settles an invoice with what a payment has left: the whole of it, or
     * `$amount` minor units, never more than either has.
     *
     * @return array<string, mixed> the payment
     */
    public function allocate(int $tenantId, int $paymentId, int $invoiceId, ?int $amount, ?int $actor): array
    {
        $this->db->transaction(fn () => $this->settle($tenantId, $paymentId, $invoiceId, $amount, $actor, 'invoice_id'));

        return $this->get($tenantId, $paymentId);
    }

    private function settle(int $tenantId, int $paymentId, int $invoiceId, ?int $amount, ?int $actor, string $field): void
    {
        $payment = $this->row($tenantId, $paymentId) ?? throw AccountingRejectedException::notFound('Payment');
        if ($payment['direction'] !== 'in' || $payment['status'] !== 'posted') {
            throw new AccountingRejectedException('Only a payment received that stands can settle an invoice', 409);
        }
        $invoice = $this->invoices->row($tenantId, $invoiceId);
        if ($invoice === null || $invoice['kind'] !== 'sale') {
            throw AccountingRejectedException::field($field, $field . ' is not a sales invoice here', 404);
        }
        if ((int) $invoice['contact_id'] !== (int) $payment['contact_id']) {
            throw AccountingRejectedException::field($field, 'This invoice is another customer\'s');
        }
        if (!in_array($invoice['status'], Invoices::OPEN, true)) {
            throw AccountingRejectedException::coded('conflict', 'Invoice ' . ($invoice['number'] ?? '') . ' has nothing left to pay', 409, $field);
        }
        $owed = (int) $invoice['total_minor'] - (int) $invoice['paid_minor'] - (int) $invoice['credited_minor'];
        $left = $this->unallocated($tenantId, $paymentId);
        $share = $amount ?? min($owed, $left);
        if ($share <= 0 || $share > $owed || $share > $left) {
            throw AccountingRejectedException::field(
                $field,
                'Allocate from 1 to ' . min($owed, $left) . ' minor units: the invoice owes ' . $owed . ' and the payment has ' . $left . ' left'
            );
        }
        $this->db->run(
            'INSERT INTO acc_allocations (tenant_id, invoice_id, payment_id, amount_minor, allocated_on, created_by_profile_id)
             VALUES (:tenant_id, :invoice_id, :payment_id, :amount, :date, :actor)',
            [':tenant_id' => $tenantId, ':invoice_id' => $invoiceId, ':payment_id' => $paymentId, ':amount' => $share,
                ':date' => max(substr((string) $payment['paid_on'], 0, 10), substr((string) $invoice['issue_date'], 0, 10)), ':actor' => $actor]
        );
        $this->invoices->refresh($tenantId, $invoiceId);
        $this->notify(Events::ACCOUNTING_PAYMENT_ALLOCATED, $tenantId, $paymentId, $actor, ['invoice_id' => $invoiceId, 'amount_minor' => $share]);
        $status = (string) ($this->invoices->row($tenantId, $invoiceId)['status'] ?? '');
        if ($status === 'paid' || $status === 'partly_paid') {
            $this->invoices->announce($status === 'paid' ? Events::ACCOUNTING_INVOICE_PAID : Events::ACCOUNTING_INVOICE_PARTLY_PAID, $tenantId, $invoiceId, $actor);
        }
    }

    // ── cheques ──────────────────────────────────────────────────────────

    /**
     * A waiting cheque was paid in: Cheques to collect → the bank.
     *
     * @return array<string, mixed>
     */
    public function deposit(int $tenantId, int $paymentId, ?int $actor, ?string $date = null, mixed $bankAccountId = null): array
    {
        $payment = $this->row($tenantId, $paymentId) ?? throw AccountingRejectedException::notFound('Payment');
        if ($payment['method'] !== 'cheque' || $payment['cheque_status'] !== 'waiting' || $payment['status'] !== 'posted') {
            throw new AccountingRejectedException('Only a cheque waiting to be collected can be deposited', 409);
        }
        $date = $date === null ? $this->today() : Periods::date($date, 'deposited_on');
        $bank = $bankAccountId === null ? $this->workspace->roleAccountId($tenantId, 'bank') : $this->moneyAccount($tenantId, $bankAccountId, 'account_id');
        $contactId = AccountingDb::int($payment['contact_id']);
        $this->db->transaction(function () use ($tenantId, $paymentId, $actor, $date, $bank, $payment, $contactId): void {
            $posted = $this->ledger->post($tenantId, $actor, [
                'journal' => 'BNK1',
                'date' => $date,
                'memo' => 'Cheque ' . ($payment['cheque_number'] ?? '') . ' deposited (' . $payment['number'] . ')',
                'source_type' => 'cheque_deposit',
                'source_id' => $paymentId,
                'idempotency_key' => 'cheque_deposit:' . $paymentId,
                'lines' => [
                    ['account_id' => $bank, 'debit_minor' => (int) $payment['amount_minor'], 'contact_id' => $contactId],
                    ['account_id' => (int) $payment['account_id'], 'credit_minor' => (int) $payment['amount_minor'], 'contact_id' => $contactId],
                ],
            ]);
            $this->db->run(
                'UPDATE acc_payments SET cheque_status = \'cleared\', deposit_entry_id = :entry_id, updated_at = CURRENT_TIMESTAMP
                  WHERE tenant_id = :tenant_id AND id = :id AND cheque_status = \'waiting\'',
                [':entry_id' => (int) $posted['entry']['id'], ':tenant_id' => $tenantId, ':id' => $paymentId]
            );
        });

        return $this->get($tenantId, $paymentId);
    }

    /**
     * A cheque bounced: its entries are reversed, what it settled is released
     * (the invoices reopen), and the bank's fee, when there is one, is posted.
     *
     * @return array<string, mixed>
     */
    public function bounce(int $tenantId, int $paymentId, ?int $actor, ?string $date = null, int $feeMinor = 0, string $note = ''): array
    {
        $payment = $this->row($tenantId, $paymentId) ?? throw AccountingRejectedException::notFound('Payment');
        if ($payment['method'] !== 'cheque' || !in_array($payment['cheque_status'], ['waiting', 'cleared'], true) || $payment['status'] !== 'posted') {
            throw new AccountingRejectedException('Only a cheque that stands can bounce', 409);
        }
        if ($feeMinor < 0) {
            throw AccountingRejectedException::field('fee_minor', 'fee_minor is 0 or more');
        }
        $date = $date === null ? $this->today() : Periods::date($date, 'bounced_on');
        $reason = trim($note) !== '' ? mb_substr(trim($note), 0, 400) : 'Cheque ' . ($payment['cheque_number'] ?? '') . ' bounced';
        $this->db->transaction(function () use ($tenantId, $paymentId, $actor, $date, $feeMinor, $reason, $payment): void {
            if ($payment['deposit_entry_id'] !== null) {
                $this->ledger->reverse($tenantId, (int) $payment['deposit_entry_id'], $actor, $reason, $date, 'cheque_bounce');
            }
            $reversal = $this->ledger->reverse($tenantId, (int) $payment['entry_id'], $actor, $reason, $date, 'cheque_bounce');
            if ($feeMinor > 0) {
                $this->ledger->post($tenantId, $actor, [
                    'journal' => 'BNK1',
                    'date' => $date,
                    'memo' => 'Bank fee for a bounced cheque (' . $payment['number'] . ')',
                    'source_type' => 'cheque_bounce',
                    'source_id' => $paymentId,
                    'idempotency_key' => 'cheque_bounce_fee:' . $paymentId,
                    'lines' => [
                        ['account_id' => $this->workspace->roleAccountId($tenantId, 'payment_fees'), 'debit_minor' => $feeMinor],
                        ['account_id' => $this->workspace->roleAccountId($tenantId, 'bank'), 'credit_minor' => $feeMinor],
                    ],
                ]);
            }
            $released = array_map('intval', array_column($this->db->all(
                'SELECT invoice_id FROM acc_allocations WHERE tenant_id = :tenant_id AND payment_id = :id AND reversed_at IS NULL',
                [':tenant_id' => $tenantId, ':id' => $paymentId]
            ), 'invoice_id'));
            $this->db->run(
                'UPDATE acc_allocations SET reversed_at = CURRENT_TIMESTAMP WHERE tenant_id = :tenant_id AND payment_id = :id AND reversed_at IS NULL',
                [':tenant_id' => $tenantId, ':id' => $paymentId]
            );
            foreach (array_unique($released) as $invoiceId) {
                $this->invoices->refresh($tenantId, $invoiceId);
            }
            $this->db->run(
                'UPDATE acc_payments SET status = \'bounced\', cheque_status = \'bounced\', bounce_entry_id = :entry_id, updated_at = CURRENT_TIMESTAMP
                  WHERE tenant_id = :tenant_id AND id = :id',
                [':entry_id' => (int) $reversal['entry']['id'], ':tenant_id' => $tenantId, ':id' => $paymentId]
            );
            $this->notify(Events::ACCOUNTING_CHEQUE_BOUNCED, $tenantId, $paymentId, $actor, ['fee_minor' => $feeMinor, 'reopened_invoice_ids' => array_values(array_unique($released))]);
        });

        return $this->get($tenantId, $paymentId);
    }

    // ── transfers ────────────────────────────────────────────────────────

    /**
     * Moves money between two of the workspace's own accounts: cash to the
     * bank, or a payment provider's payout to the bank.
     *
     * @param array<string, mixed> $data `from_account_id`, `to_account_id`, `amount_minor` or `amount`, `paid_on`, `reference`, `notes`
     * @param array{agent_id?: ?int, on_behalf_of?: ?int, external_ref?: ?string, owner_plugin?: ?string} $meta
     * @return array{payment: array<string, mixed>, repeated: bool}
     */
    public function transfer(int $tenantId, ?int $actor, array $data, array $meta = []): array
    {
        $this->workspace->ensure($tenantId);
        $external = self::externalRef($meta['external_ref'] ?? null);
        if ($external !== null && ($done = $this->idByExternal($tenantId, $external)) !== null) {
            return ['payment' => $this->get($tenantId, $done), 'repeated' => true];
        }
        $currency = $this->workspace->books($tenantId)['base_currency'];
        $from = $this->moneyAccount($tenantId, $data['from_account_id'] ?? null, 'from_account_id');
        $to = $this->moneyAccount($tenantId, $data['to_account_id'] ?? null, 'to_account_id');
        if ($from === $to) {
            throw AccountingRejectedException::field('to_account_id', 'A transfer goes to another account');
        }
        $amount = $this->amount($data, $currency, 'amount');
        $date = isset($data['paid_on']) && $data['paid_on'] !== '' ? Periods::date($data['paid_on'], 'paid_on') : $this->today();
        $reference = self::text($data['reference'] ?? null, 200, 'reference', false) ?? '';
        $notes = is_string($data['notes'] ?? null) ? mb_substr(trim($data['notes']), 0, 2000) : '';
        $cash = $this->workspace->roleAccountId($tenantId, 'cash');

        try {
            $id = $this->db->transaction(function () use ($tenantId, $actor, $from, $to, $amount, $currency, $date, $reference, $notes, $external, $meta, $cash): int {
                $year = substr($date, 0, 4);
                $number = sprintf('TRF-%s-%04d', $year, $this->sequences->next($tenantId, 'acc_payments:transfer:' . $year));
                $id = $this->db->insert(
                    'INSERT INTO acc_payments (tenant_id, direction, number, paid_on, amount_minor, currency, method, account_id, from_account_id,
                            reference, status, notes, external_ref, created_by_profile_id, agent_id, on_behalf_of_profile_id, owner_plugin)
                     VALUES (:tenant_id, \'transfer\', :number, :paid_on, :amount, :currency, \'bank_transfer\', :to, :from,
                            :reference, \'posted\', :notes, :external, :actor, :agent, :on_behalf, :owner)',
                    [
                        ':tenant_id' => $tenantId, ':number' => $number, ':paid_on' => $date, ':amount' => $amount, ':currency' => $currency,
                        ':to' => $to, ':from' => $from, ':reference' => $reference, ':notes' => $notes, ':external' => $external,
                        ':actor' => $actor, ':agent' => AccountingDb::int($meta['agent_id'] ?? null),
                        ':on_behalf' => AccountingDb::int($meta['on_behalf_of'] ?? null),
                        ':owner' => is_string($meta['owner_plugin'] ?? null) ? $meta['owner_plugin'] : null,
                    ]
                );
                $posted = $this->ledger->post($tenantId, $actor, [
                    'journal' => $from === $cash ? 'CSH' : 'BNK1',
                    'date' => $date,
                    'memo' => 'Transfer ' . $number . ($reference !== '' ? ' · ' . $reference : ''),
                    'source_type' => 'transfer',
                    'source_id' => $id,
                    'idempotency_key' => 'transfer:' . $id,
                    'lines' => [
                        ['account_id' => $to, 'debit_minor' => $amount, 'description' => $reference],
                        ['account_id' => $from, 'credit_minor' => $amount, 'description' => $reference],
                    ],
                ]);
                $this->db->run(
                    'UPDATE acc_payments SET entry_id = :entry_id WHERE tenant_id = :tenant_id AND id = :id',
                    [':entry_id' => (int) $posted['entry']['id'], ':tenant_id' => $tenantId, ':id' => $id]
                );
                $this->notify(Events::ACCOUNTING_PAYMENT_RECORDED, $tenantId, $id, $actor);

                return $id;
            });
        } catch (\PDOException $e) {
            if ($external !== null && AccountingDb::isUniqueViolation($e) && ($done = $this->idByExternal($tenantId, $external)) !== null) {
                return ['payment' => $this->get($tenantId, $done), 'repeated' => true];
            }
            throw $e;
        }

        return ['payment' => $this->get($tenantId, $id), 'repeated' => false];
    }

    // ── reading ──────────────────────────────────────────────────────────

    /**
     * @param array{direction?: string, contact_id?: int, method?: string, cheque_status?: string, from?: string, to?: string, q?: string} $filter
     * @return list<array<string, mixed>>
     */
    public function list(int $tenantId, array $filter = [], int $limit = 50, int $offset = 0): array
    {
        $this->workspace->ensure($tenantId);
        $where = ['p.tenant_id = :tenant_id'];
        $params = [':tenant_id' => $tenantId];
        if (isset($filter['direction'])) {
            if (!in_array($filter['direction'], self::DIRECTIONS, true)) {
                throw AccountingRejectedException::field('direction', 'direction is in, out or transfer');
            }
            $where[] = 'p.direction = :direction';
            $params[':direction'] = $filter['direction'];
        }
        if (isset($filter['contact_id'])) {
            $where[] = 'p.contact_id = :contact_id';
            $params[':contact_id'] = (int) $filter['contact_id'];
        }
        if (isset($filter['method'])) {
            $where[] = 'p.method = :method';
            $params[':method'] = (string) $filter['method'];
        }
        if (isset($filter['cheque_status'])) {
            $where[] = 'p.cheque_status = :cheque_status';
            $params[':cheque_status'] = (string) $filter['cheque_status'];
        }
        if (isset($filter['from'])) {
            $where[] = 'p.paid_on >= :from';
            $params[':from'] = Periods::date($filter['from'], 'from');
        }
        if (isset($filter['to'])) {
            $where[] = 'p.paid_on <= :to';
            $params[':to'] = Periods::date($filter['to'], 'to');
        }
        if (isset($filter['q']) && trim($filter['q']) !== '') {
            $where[] = '(LOWER(p.number) LIKE :q OR LOWER(p.reference) LIKE :q2 OR LOWER(COALESCE(c.name, \'\')) LIKE :q3)';
            $params[':q'] = '%' . mb_strtolower(trim($filter['q'])) . '%';
            $params[':q2'] = $params[':q'];
            $params[':q3'] = $params[':q'];
        }
        $rows = $this->db->all(
            'SELECT ' . self::COLUMNS . ', c.name AS contact_name,
                    (SELECT COALESCE(SUM(a.amount_minor), 0) FROM acc_allocations a
                      WHERE a.tenant_id = p.tenant_id AND a.payment_id = p.id AND a.reversed_at IS NULL) AS allocated
               FROM acc_payments p LEFT JOIN contacts c ON c.id = p.contact_id AND c.tenant_id = p.tenant_id
              WHERE ' . implode(' AND ', $where) . '
              ORDER BY p.paid_on DESC, p.id DESC
              LIMIT ' . max(1, min(200, $limit)) . ' OFFSET ' . max(0, $offset),
            $params
        );

        return array_map(self::present(...), $rows);
    }

    /** @return array<string, mixed> */
    public function get(int $tenantId, int $id): array
    {
        $this->workspace->ensure($tenantId);
        $row = $this->db->one(
            'SELECT ' . self::COLUMNS . ', c.name AS contact_name,
                    (SELECT COALESCE(SUM(a.amount_minor), 0) FROM acc_allocations a
                      WHERE a.tenant_id = p.tenant_id AND a.payment_id = p.id AND a.reversed_at IS NULL) AS allocated
               FROM acc_payments p LEFT JOIN contacts c ON c.id = p.contact_id AND c.tenant_id = p.tenant_id
              WHERE p.tenant_id = :tenant_id AND p.id = :id',
            [':tenant_id' => $tenantId, ':id' => $id]
        ) ?? throw AccountingRejectedException::notFound('Payment');
        $allocations = $this->db->all(
            'SELECT a.id, a.invoice_id, i.number AS invoice_number, a.amount_minor, a.allocated_on, a.reversed_at
               FROM acc_allocations a JOIN acc_invoices i ON i.id = a.invoice_id AND i.tenant_id = a.tenant_id
              WHERE a.tenant_id = :tenant_id AND a.payment_id = :id ORDER BY a.id',
            [':tenant_id' => $tenantId, ':id' => $id]
        );

        return self::present($row) + ['allocations' => array_map(static fn (array $a): array => [
            'id' => (int) $a['id'],
            'invoice_id' => (int) $a['invoice_id'],
            'invoice_number' => is_string($a['invoice_number']) ? $a['invoice_number'] : null,
            'amount_minor' => (int) $a['amount_minor'],
            'allocated_on' => substr((string) $a['allocated_on'], 0, 10),
            'released' => $a['reversed_at'] !== null,
        ], $allocations)];
    }

    /** @return array<string, mixed>|null */
    public function row(int $tenantId, int $id): ?array
    {
        return $this->db->one('SELECT ' . self::COLUMNS . ' FROM acc_payments p WHERE p.tenant_id = :tenant_id AND p.id = :id', [':tenant_id' => $tenantId, ':id' => $id]);
    }

    /** What a payment received has not settled yet: the customer's credit from it. */
    public function unallocated(int $tenantId, int $paymentId): int
    {
        $row = $this->db->one(
            'SELECT p.amount_minor - COALESCE((SELECT SUM(a.amount_minor) FROM acc_allocations a
                     WHERE a.tenant_id = p.tenant_id AND a.payment_id = p.id AND a.reversed_at IS NULL), 0) AS left_minor
               FROM acc_payments p WHERE p.tenant_id = :tenant_id AND p.id = :id',
            [':tenant_id' => $tenantId, ':id' => $paymentId]
        );

        return (int) ($row['left_minor'] ?? 0);
    }

    // ── helpers ──────────────────────────────────────────────────────────

    /**
     * @param array<string, mixed> $data
     * @return list<array{0: int, 1: ?int}>
     */
    private function wantedAllocations(array $data): array
    {
        if (isset($data['invoice_id'])) {
            if (!is_int($data['invoice_id'])) {
                throw AccountingRejectedException::field('invoice_id', 'invoice_id is an invoice id');
            }

            return [[$data['invoice_id'], null]];
        }
        $raw = $data['allocations'] ?? [];
        if (!is_array($raw) || !array_is_list($raw) || count($raw) > 100) {
            throw AccountingRejectedException::field('allocations', 'allocations is a list of up to 100 {invoice_id, amount_minor}');
        }
        $out = [];
        foreach ($raw as $i => $a) {
            $invoiceId = is_array($a) ? ($a['invoice_id'] ?? null) : null;
            $share = is_array($a) ? ($a['amount_minor'] ?? null) : null;
            if (!is_int($invoiceId) || ($share !== null && !is_int($share))) {
                throw AccountingRejectedException::field('allocations[' . $i . ']', 'allocations[' . $i . '] is {invoice_id, amount_minor}');
            }
            $out[] = [$invoiceId, $share];
        }

        return $out;
    }

    /** @param array<string, mixed> $data */
    private function amount(array $data, string $currency, string $field): int
    {
        if (isset($data['amount_minor'])) {
            $minor = $data['amount_minor'];
            if (!is_int($minor) || $minor <= 0) {
                throw AccountingRejectedException::field('amount_minor', 'amount_minor is a whole number of minor units above 0');
            }

            return $minor;
        }
        $typed = $data[$field] ?? null;
        if (!is_string($typed) && !is_int($typed)) {
            throw AccountingRejectedException::field($field, $field . ' is what was paid, such as "125.500", or give amount_minor');
        }
        try {
            $minor = Currency::parse((string) $typed, $currency);
        } catch (MoneyException) {
            throw AccountingRejectedException::field($field, $field . ' is an amount in ' . $currency . ' with its decimals, such as "125.500"');
        }
        if ($minor <= 0) {
            throw AccountingRejectedException::field($field, $field . ' must be more than 0');
        }

        return $minor;
    }

    private function customer(int $tenantId, mixed $contactId): int
    {
        $contact = is_int($contactId) ? $this->db->one(
            'SELECT id, name, is_customer, archived_at FROM contacts WHERE tenant_id = :tenant_id AND id = :id',
            [':tenant_id' => $tenantId, ':id' => $contactId]
        ) : null;
        if ($contact === null || $contact['archived_at'] !== null) {
            throw AccountingRejectedException::field('contact_id', 'contact_id is a customer here');
        }
        if (!DbBool::of($contact['is_customer'])) {
            throw AccountingRejectedException::field('contact_id', $contact['name'] . ' is not marked as a customer; mark them as one on their contact page first');
        }

        return (int) $contact['id'];
    }

    /** An account money sits in: an asset account that takes lines and isn't a receivable. */
    private function moneyAccount(int $tenantId, mixed $id, string $field): int
    {
        $row = is_int($id) ? $this->db->one(
            'SELECT id, type, subtype, is_group, archived_at FROM acc_accounts WHERE tenant_id = :tenant_id AND id = :id',
            [':tenant_id' => $tenantId, ':id' => $id]
        ) : null;
        if ($row === null || $row['archived_at'] !== null || DbBool::of($row['is_group'])) {
            throw AccountingRejectedException::field($field, $field . ' is an account here that takes lines');
        }
        if ($row['type'] !== 'asset' || !in_array($row['subtype'], ['bank', 'cash', 'current_asset'], true)) {
            throw AccountingRejectedException::field($field, $field . ' must be a bank, cash or money-in-transit account');
        }

        return (int) $row['id'];
    }

    private function contactName(int $tenantId, int $contactId): string
    {
        return (string) ($this->db->one('SELECT name FROM contacts WHERE tenant_id = :tenant_id AND id = :id', [':tenant_id' => $tenantId, ':id' => $contactId])['name'] ?? '');
    }

    private function idByExternal(int $tenantId, string $external): ?int
    {
        return AccountingDb::int($this->db->one(
            'SELECT id FROM acc_payments WHERE tenant_id = :tenant_id AND external_ref = :ref',
            [':tenant_id' => $tenantId, ':ref' => $external]
        )['id'] ?? null);
    }

    private static function externalRef(mixed $ref): ?string
    {
        if ($ref === null || $ref === '') {
            return null;
        }
        if (!is_string($ref) || mb_strlen($ref) > 160) {
            throw AccountingRejectedException::field('external_ref', 'external_ref is text of up to 160 characters');
        }

        return $ref;
    }

    private static function text(mixed $value, int $max, string $field, bool $required): ?string
    {
        if ($value === null || (is_string($value) && trim($value) === '')) {
            if ($required) {
                throw AccountingRejectedException::field($field, $field . ' is needed');
            }

            return null;
        }
        if (!is_string($value)) {
            throw AccountingRejectedException::field($field, $field . ' is text');
        }

        return mb_substr(trim($value), 0, $max);
    }

    private function today(): string
    {
        return ($this->clock !== null ? ($this->clock)() : new \DateTimeImmutable('now', new \DateTimeZone('UTC')))->format('Y-m-d');
    }

    /** @param array<string, mixed> $extra */
    private function notify(string $event, int $tenantId, int $id, ?int $actor, array $extra = []): void
    {
        $p = $this->get($tenantId, $id);
        $payload = [
            'tenant_id' => $tenantId, 'payment_id' => $id, 'direction' => $p['direction'], 'number' => $p['number'],
            'contact_id' => $p['contact_id'], 'paid_on' => $p['paid_on'], 'amount_minor' => $p['amount_minor'],
            'currency' => $p['currency'], 'method' => $p['method'], 'status' => $p['status'], 'cheque_status' => $p['cheque_status'],
            'unallocated_minor' => $p['unallocated_minor'], 'acting_profile_id' => $actor,
        ] + $extra;
        $this->hooks?->dispatch($event, $payload);
        try {
            $this->hooks?->dispatchAsync($event . '.async', $payload);
        } catch (\Throwable $e) {
            error_log('[accounting] ' . $event . '.async could not be queued: ' . $e->getMessage());
        }
    }

    /**
     * @param array<string, mixed> $r
     * @return array<string, mixed>
     */
    private static function present(array $r): array
    {
        $amount = (int) $r['amount_minor'];
        $allocated = (int) ($r['allocated'] ?? 0);

        return [
            'id' => (int) $r['id'],
            'direction' => (string) $r['direction'],
            'number' => (string) $r['number'],
            'contact_id' => AccountingDb::int($r['contact_id']),
            'contact_name' => is_string($r['contact_name'] ?? null) ? $r['contact_name'] : null,
            'paid_on' => substr((string) $r['paid_on'], 0, 10),
            'amount_minor' => $amount,
            'currency' => (string) $r['currency'],
            'method' => (string) $r['method'],
            'account_id' => (int) $r['account_id'],
            'from_account_id' => AccountingDb::int($r['from_account_id']),
            'reference' => (string) $r['reference'],
            'cheque_number' => is_string($r['cheque_number']) ? $r['cheque_number'] : null,
            'cheque_bank' => is_string($r['cheque_bank']) ? $r['cheque_bank'] : null,
            'cheque_due_on' => is_string($r['cheque_due_on']) ? substr($r['cheque_due_on'], 0, 10) : null,
            'cheque_status' => is_string($r['cheque_status']) ? $r['cheque_status'] : null,
            'fee_minor' => (int) $r['fee_minor'],
            'status' => (string) $r['status'],
            'allocated_minor' => $allocated,
            'unallocated_minor' => $r['direction'] === 'in' && $r['status'] === 'posted' ? max(0, $amount - $allocated) : 0,
            'notes' => (string) $r['notes'],
            'entry_id' => AccountingDb::int($r['entry_id']),
            'deposit_entry_id' => AccountingDb::int($r['deposit_entry_id']),
            'bounce_entry_id' => AccountingDb::int($r['bounce_entry_id']),
            'external_ref' => is_string($r['external_ref']) ? $r['external_ref'] : null,
            'created_by_profile_id' => AccountingDb::int($r['created_by_profile_id']),
            'agent_id' => AccountingDb::int($r['agent_id']),
            'on_behalf_of_profile_id' => AccountingDb::int($r['on_behalf_of_profile_id']),
            'owner_plugin' => is_string($r['owner_plugin']) ? $r['owner_plugin'] : null,
            'created_at' => (string) $r['created_at'],
        ];
    }
}
