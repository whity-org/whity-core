<?php

declare(strict_types=1);

namespace Whity\Core\Accounting;

use Whity\Core\Db\DbBool;
use Whity\Core\Money\Currency;
use Whity\Core\Money\MoneyException;
use Whity\Sdk\Hooks\Events;

/**
 * Expense claims and petty cash (WHIT-1318 A3b, design section 32 §5.3,
 * register MO.1, Q5).
 *
 * AN EXPENSE CLAIM is a supplier bill whose supplier is the member of staff
 * who paid. Their own contact (`contacts.profile_id`, made the first time
 * they claim) is the supplier, so the claim posts Dr each cost and the tax to
 * reclaim, Cr We owe suppliers under their name. It travels the bill route
 * like any bill, and is reimbursed as a payment made to them. Amounts are
 * typed as they were paid, tax included.
 *
 * PETTY CASH is cash kept on hand in the Petty cash account. A spend from it
 * is a bill from "Cash purchases" paid at once from Petty cash, so its costs
 * and its reclaimable tax reach the books and the return like any bill. Top it
 * up with a transfer from the bank.
 *
 * TENANT-OWNED: every statement binds `tenant_id`.
 */
final class Expenses
{
    /** The contact petty cash spends are made with, per workspace. */
    public const CASH_PURCHASES = 'Cash purchases';

    public function __construct(
        private readonly AccountingDb $db,
        private readonly AccountingWorkspace $workspace,
        private readonly Invoices $invoices,
        private readonly Payments $payments,
    ) {
    }

    /**
     * A person files a claim for money they spent; it is posted, or sent on
     * the bill route when the workspace has one.
     *
     * @param array<string, mixed> $data `date`, `lines` (each `description`, `amount` as paid, tax included, or
     *     `amount_minor`, `tax_category` or `tax_code`, `account_id` or `account_code`), `notes`, `receipt_stored_file_id`
     * @param array{agent_id?: ?int, on_behalf_of?: ?int, external_ref?: ?string} $meta
     * @return array<string, mixed> the claim, as an invoice of kind `purchase`
     */
    public function claim(int $tenantId, int $profileId, array $data, array $meta = []): array
    {
        $this->workspace->ensure($tenantId);
        $contactId = $this->staffContact($tenantId, $profileId);
        $receipt = $this->receipt($tenantId, $data['receipt_stored_file_id'] ?? null);
        $made = $this->invoices->create($tenantId, $profileId, [
            'kind' => 'purchase',
            'contact_id' => $contactId,
            'issue_date' => $data['date'] ?? null,
            'payment_terms_days' => 0,
            'prices_include_tax' => true,
            'notes' => $data['notes'] ?? '',
            'lines' => $this->lines($tenantId, $data['lines'] ?? null),
        ], $meta);
        $id = (int) $made['invoice']['id'];
        if ($made['repeated']) {
            return $this->invoices->get($tenantId, $id);
        }
        $this->db->run(
            'UPDATE acc_invoices SET claimant_profile_id = :profile, receipt_stored_file_id = :receipt WHERE tenant_id = :tenant_id AND id = :id',
            [':profile' => $profileId, ':receipt' => $receipt, ':tenant_id' => $tenantId, ':id' => $id]
        );

        $claim = $this->invoices->issue($tenantId, $id, $profileId);
        $this->invoices->announce(Events::ACCOUNTING_EXPENSE_CLAIMED, $tenantId, $id, $profileId, ['claimant_profile_id' => $profileId]);

        return $claim;
    }

    /**
     * Claims, newest first: one person's, or everyone's.
     *
     * @return list<array<string, mixed>>
     */
    public function claims(int $tenantId, ?int $profileId, int $limit = 50, int $offset = 0): array
    {
        $this->workspace->ensure($tenantId);
        $params = [':tenant_id' => $tenantId];
        $where = 'tenant_id = :tenant_id AND claimant_profile_id IS NOT NULL';
        if ($profileId !== null) {
            $where .= ' AND claimant_profile_id = :profile';
            $params[':profile'] = $profileId;
        }
        $ids = array_map('intval', array_column($this->db->all(
            'SELECT id FROM acc_invoices WHERE ' . $where . ' ORDER BY issue_date DESC, id DESC LIMIT ' . max(1, min(200, $limit)) . ' OFFSET ' . max(0, $offset),
            $params
        ), 'id'));

        return array_map(fn (int $id): array => $this->invoices->get($tenantId, $id), $ids);
    }

    /**
     * One claim; a person who isn't its claimant sees it only when `$all`.
     *
     * @return array<string, mixed>
     */
    public function get(int $tenantId, int $id, ?int $onlyFor = null): array
    {
        $claim = $this->invoices->get($tenantId, $id);
        if ($claim['claimant_profile_id'] === null || ($onlyFor !== null && $claim['claimant_profile_id'] !== $onlyFor)) {
            throw AccountingRejectedException::notFound('Expense claim');
        }

        return $claim;
    }

    /**
     * Pays a person back what their claim is still owed.
     *
     * @param array<string, mixed> $data `method` (cash, bank_transfer, cliq...), `account_id`, `paid_on`, `reference`
     * @return array<string, mixed> the payment
     */
    public function reimburse(int $tenantId, int $claimId, ?int $actor, array $data): array
    {
        $claim = $this->get($tenantId, $claimId);
        if (!in_array($claim['status'], Invoices::OPEN, true) || (int) $claim['due_minor'] <= 0) {
            throw new AccountingRejectedException(
                $claim['status'] === 'waiting_approval' ? 'This claim is waiting for approval' : 'Nothing is owed on this claim',
                409
            );
        }

        return $this->payments->pay($tenantId, $actor, array_filter([
            'contact_id' => (int) $claim['contact_id'],
            'amount_minor' => (int) $claim['due_minor'],
            'method' => $data['method'] ?? 'bank_transfer',
            'account_id' => $data['account_id'] ?? null,
            'paid_on' => $data['paid_on'] ?? null,
            'reference' => $data['reference'] ?? 'Expense claim ' . $claim['number'],
            'invoice_id' => $claimId,
        ], static fn (mixed $v): bool => $v !== null))['payment'];
    }

    // ── petty cash ───────────────────────────────────────────────────────

    /**
     * Records money spent from petty cash: a bill from Cash purchases, posted
     * and paid at once from the Petty cash account. It is already spent, so it
     * does not wait on the bill route.
     *
     * @param array<string, mixed> $data `date`, `lines` as {@see self::claim()}, `notes` (what and where), `receipt_stored_file_id`
     * @param array{agent_id?: ?int, on_behalf_of?: ?int, external_ref?: ?string} $meta
     * @return array{bill: array<string, mixed>, payment: array<string, mixed>}
     */
    public function spend(int $tenantId, ?int $actor, array $data, array $meta = []): array
    {
        $this->workspace->ensure($tenantId);
        $pettyCash = $this->workspace->roleAccountId($tenantId, 'petty_cash');
        $receipt = $this->receipt($tenantId, $data['receipt_stored_file_id'] ?? null);

        return $this->db->transaction(function () use ($tenantId, $actor, $data, $meta, $pettyCash, $receipt): array {
            $made = $this->invoices->create($tenantId, $actor, [
                'kind' => 'purchase',
                'contact_id' => $this->cashPurchases($tenantId),
                'issue_date' => $data['date'] ?? null,
                'payment_terms_days' => 0,
                'prices_include_tax' => true,
                'notes' => $data['notes'] ?? '',
                'lines' => $this->lines($tenantId, $data['lines'] ?? null),
            ], $meta);
            $id = (int) $made['invoice']['id'];
            if ($made['repeated']) {
                $bill = $this->invoices->get($tenantId, $id);
                $paymentId = (int) ($bill['allocations'][0]['payment_id'] ?? 0);

                return ['bill' => $bill, 'payment' => $this->payments->get($tenantId, $paymentId)];
            }
            if ($receipt !== null) {
                $this->db->run(
                    'UPDATE acc_invoices SET receipt_stored_file_id = :receipt WHERE tenant_id = :tenant_id AND id = :id',
                    [':receipt' => $receipt, ':tenant_id' => $tenantId, ':id' => $id]
                );
            }
            $bill = $this->invoices->issue($tenantId, $id, $actor, false);
            $payment = $this->payments->pay($tenantId, $actor, [
                'contact_id' => (int) $bill['contact_id'],
                'amount_minor' => (int) $bill['total_minor'],
                'method' => 'cash',
                'account_id' => $pettyCash,
                'paid_on' => $bill['issue_date'],
                'reference' => 'Petty cash ' . $bill['number'],
                'invoice_id' => $id,
            ])['payment'];

            return ['bill' => $this->invoices->get($tenantId, $id), 'payment' => $payment];
        });
    }

    /**
     * The petty cash account and what is in it.
     *
     * @return array{account_id: int, code: string, balance_minor: int, currency: string}
     */
    public function pettyCash(int $tenantId, string $asOf): array
    {
        $this->workspace->ensure($tenantId);
        $id = $this->workspace->roleAccountId($tenantId, 'petty_cash');
        $row = $this->db->one('SELECT code FROM acc_accounts WHERE tenant_id = :tenant_id AND id = :id', [':tenant_id' => $tenantId, ':id' => $id]);
        $balance = (int) ($this->db->one(
            'SELECT COALESCE(SUM(debit_minor - credit_minor), 0) AS bal FROM acc_lines WHERE tenant_id = :tenant_id AND account_id = :id AND entry_date <= :as_of',
            [':tenant_id' => $tenantId, ':id' => $id, ':as_of' => Periods::date($asOf, 'as_of')]
        )['bal'] ?? 0);

        return ['account_id' => $id, 'code' => (string) ($row['code'] ?? ''), 'balance_minor' => $balance, 'currency' => $this->workspace->books($tenantId)['base_currency']];
    }

    // ── helpers ──────────────────────────────────────────────────────────

    /**
     * Claim lines as the invoice engine takes them: one of each, at the
     * amount paid with tax included.
     *
     * @return list<array<string, mixed>>
     */
    private function lines(int $tenantId, mixed $raw): array
    {
        if (!is_array($raw) || !array_is_list($raw) || $raw === [] || count($raw) > 50) {
            throw AccountingRejectedException::field('lines', 'lines is a list of 1 to 50 {description, amount}');
        }
        $currency = $this->workspace->books($tenantId)['base_currency'];
        $out = [];
        foreach ($raw as $i => $line) {
            $field = 'lines[' . $i . ']';
            if (!is_array($line)) {
                throw AccountingRejectedException::field($field, $field . ' is {description, amount}');
            }
            $amount = $line['amount_minor'] ?? null;
            if ($amount === null) {
                $typed = $line['amount'] ?? null;
                if (!is_string($typed) && !is_int($typed)) {
                    throw AccountingRejectedException::field($field . '.amount', $field . '.amount is what was paid, tax included, such as "12.500"');
                }
                try {
                    $amount = Currency::parse((string) $typed, $currency);
                } catch (MoneyException) {
                    throw AccountingRejectedException::field($field . '.amount', $field . '.amount is an amount in ' . $currency . ', such as "12.500"');
                }
            }
            if (!is_int($amount) || $amount <= 0) {
                throw AccountingRejectedException::field($field . '.amount', $field . '.amount must be more than 0');
            }
            $out[] = array_filter([
                'description' => $line['description'] ?? null,
                'quantity' => '1',
                'unit_price_minor' => $amount,
                'tax_category' => $line['tax_category'] ?? null,
                'tax_code' => $line['tax_code'] ?? null,
                'account_id' => $line['account_id'] ?? null,
                'account_code' => $line['account_code'] ?? null,
                'ou_id' => $line['ou_id'] ?? null,
            ], static fn (mixed $v): bool => $v !== null);
        }

        return $out;
    }

    /** The person's own contact, made a supplier, created the first time they claim. */
    private function staffContact(int $tenantId, int $profileId): int
    {
        $member = $this->db->one(
            'SELECT p.display_name FROM profiles p JOIN memberships m ON m.profile_id = p.id
              WHERE p.id = :profile AND m.tenant_id = :tenant_id AND m.status = \'active\'',
            [':profile' => $profileId, ':tenant_id' => $tenantId]
        ) ?? throw new AccountingRejectedException('Only a member of this workspace can claim expenses here', 403);
        $contact = $this->db->one(
            'SELECT id, is_supplier, archived_at FROM contacts WHERE tenant_id = :tenant_id AND profile_id = :profile ORDER BY id LIMIT 1',
            [':tenant_id' => $tenantId, ':profile' => $profileId]
        );
        if ($contact !== null) {
            if (!DbBool::of($contact['is_supplier']) || $contact['archived_at'] !== null) {
                $this->db->run(
                    'UPDATE contacts SET is_supplier = TRUE, archived_at = NULL WHERE tenant_id = :tenant_id AND id = :id',
                    [':tenant_id' => $tenantId, ':id' => (int) $contact['id']]
                );
            }

            return (int) $contact['id'];
        }

        return $this->db->insert(
            'INSERT INTO contacts (tenant_id, name, is_customer, is_supplier, profile_id, created_by_profile_id)
             VALUES (:tenant_id, :name, FALSE, TRUE, :profile, :profile2)',
            [':tenant_id' => $tenantId, ':name' => (string) $member['display_name'], ':profile' => $profileId, ':profile2' => $profileId]
        );
    }

    /** The workspace's Cash purchases contact, made the first time petty cash is spent. */
    private function cashPurchases(int $tenantId): int
    {
        $found = AccountingDb::int($this->db->one(
            'SELECT id FROM contacts WHERE tenant_id = :tenant_id AND name = :name AND is_supplier = TRUE AND profile_id IS NULL ORDER BY id LIMIT 1',
            [':tenant_id' => $tenantId, ':name' => self::CASH_PURCHASES]
        )['id'] ?? null);

        return $found ?? $this->db->insert(
            'INSERT INTO contacts (tenant_id, name, is_customer, is_supplier) VALUES (:tenant_id, :name, FALSE, TRUE)',
            [':tenant_id' => $tenantId, ':name' => self::CASH_PURCHASES]
        );
    }

    private function receipt(int $tenantId, mixed $id): ?int
    {
        if ($id === null) {
            return null;
        }
        if (!is_int($id) || $this->db->one(
            'SELECT 1 AS hit FROM stored_files WHERE tenant_id = :tenant_id AND id = :id',
            [':tenant_id' => $tenantId, ':id' => $id]
        ) === null) {
            throw AccountingRejectedException::field('receipt_stored_file_id', 'receipt_stored_file_id is a file uploaded to this workspace');
        }

        return $id;
    }
}
