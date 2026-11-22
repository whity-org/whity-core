<?php

declare(strict_types=1);

namespace Whity\Core\Accounting;

use Whity\Core\Db\DbBool;
use Whity\Core\Hooks\HookManager;
use Whity\Core\Inventory\InventoryRejectedException;
use Whity\Core\Inventory\ItemService;
use Whity\Core\Inventory\UnitCatalog;
use Whity\Core\Settings\SettingsRegistry;
use Whity\Sdk\Accounting\EInvoiceResult;
use Whity\Sdk\Accounting\Invoice;
use Whity\Sdk\Contribute\WorkspaceEInvoicing;
use Whity\Sdk\Hooks\Events;
use Whity\Sdk\Hooks\HookVetoException;
use Whity\Sdk\Inventory\Quantity;
use Whity\Sdk\Sql\SequenceAllocator;

/**
 * Sales invoices and credit notes (WHIT-1318 A2a, design section 32 §5.2,
 * §3.6, register AC.10–AC.12).
 *
 * A DRAFT is edited freely and posts nothing. ISSUING gives it the next number
 * in its series (`INV-2026-0001`, credit notes `CRN-2026-0001`), so numbers
 * have no gaps, freezes it, and posts one entry in the sales journal:
 *
 *     Dr Customers owe us              total          (the customer on the line)
 *     Cr each line's income account    net            (tax code, tax base = net)
 *     Cr each tax code's account       tax            (tax code, no base)
 *
 * A credit note posts the mirror and settles its invoice through
 * `acc_allocations`. Every income line carries its tax code and its net as the
 * tax base, zero-rated and exempt lines included, so the sales tax return (A6)
 * reads each code's base from the income lines and its tax from the tax lines.
 *
 * When the workspace chose an invoice route (`accounting.invoice_route_template_id`)
 * issuing sends the invoice for approval first (route subject
 * `accounting.invoice`); approved, it is issued. `accounting.invoice.issuing`
 * may veto. After commit, an e-invoicing connector bound to the container
 * reports it (`WorkspaceEInvoicing`); a failure leaves it `pending`.
 *
 * Until multi-currency (A7) an invoice is in the books' base currency.
 * Payments (A2b) settle invoices through allocations as credit notes do.
 *
 * TENANT-OWNED: every statement binds `tenant_id`.
 */
final class Invoices
{
    public const SALE_KINDS = ['sale', 'sale_credit'];
    public const PURCHASE_KINDS = ['purchase', 'purchase_credit'];

    /**
     * What differs between a sales invoice and a supplier bill: the tax
     * direction, the contact's flag, the account the counterpart owes on, the
     * journal and the number series. Credits are the mirror of their side.
     *
     * @var array<string, array{kinds: list<string>, direction: string, flag: string, who: string, counter: string, tax: string, journal: string, prefix: array{0: string, 1: string}, noun: string}>
     */
    private const SIDES = [
        'sale' => [
            'kinds' => ['sale', 'sale_credit'], 'direction' => 'output', 'flag' => 'is_customer', 'who' => 'customer',
            'counter' => 'receivable', 'tax' => 'sales_tax_output', 'journal' => 'SAL', 'prefix' => ['INV', 'CRN'], 'noun' => 'invoice',
        ],
        'purchase' => [
            'kinds' => ['purchase', 'purchase_credit'], 'direction' => 'input', 'flag' => 'is_supplier', 'who' => 'supplier',
            'counter' => 'payable', 'tax' => 'sales_tax_input', 'journal' => 'PUR', 'prefix' => ['BIL', 'SCR'], 'noun' => 'bill',
        ],
    ];
    public const STATUSES = ['draft', 'waiting_approval', 'issued', 'partly_paid', 'paid', 'credited', 'cancelled'];
    public const OPEN = ['issued', 'partly_paid'];
    public const MAX_LINES = 300;

    /** Free lines keep up to three decimals ("2.5 hours"). */
    private const FREE_DECIMALS = 3;

    private const COLUMNS = 'i.id, i.kind, i.number, i.supplier_number, i.contact_id, i.issue_date, i.supply_date, i.due_date, i.payment_terms_days, i.status,
        i.currency, i.rate_num, i.rate_den, i.prices_include_tax, i.subtotal_minor, i.discount_minor, i.tax_minor, i.total_minor,
        i.paid_minor, i.credited_minor, i.credits_invoice_id, i.entry_id, i.document_id, i.route_id, i.payment_link_ref,
        i.einvoice_status, i.einvoice_uuid, i.einvoice_ref, i.einvoice_qr, i.einvoice_counter, i.einvoice_message, i.notes,
        i.external_ref, i.quote_id, i.issued_at, i.issued_by_profile_id, i.created_by_profile_id, i.agent_id,
        i.on_behalf_of_profile_id, i.owner_plugin, i.claimant_profile_id, i.receipt_stored_file_id, i.created_at, i.updated_at';

    private ?InvoiceApprovals $approvals = null;

    private ?InvoiceApprovals $billApprovals = null;

    /** @var \Closure(): ?WorkspaceEInvoicing */
    private readonly \Closure $einvoicing;

    /**
     * @param \Closure(int): array<string, mixed> $settings the workspace's effective settings
     * @param (\Closure(): ?WorkspaceEInvoicing)|null $einvoicing the bound connector; the container's by default
     * @param (\Closure(): \DateTimeImmutable)|null $clock
     */
    public function __construct(
        private readonly AccountingDb $db,
        private readonly AccountingWorkspace $workspace,
        private readonly Ledger $ledger,
        private readonly Taxes $taxes,
        private readonly ItemService $items,
        private readonly UnitCatalog $units,
        private readonly SequenceAllocator $sequences,
        private readonly \Closure $settings,
        private readonly ?HookManager $hooks = null,
        ?\Closure $einvoicing = null,
        private readonly ?\Closure $clock = null,
    ) {
        $this->einvoicing = $einvoicing ?? static function (): ?WorkspaceEInvoicing {
            $bound = $GLOBALS['whity_services'][WorkspaceEInvoicing::class] ?? null;

            return $bound instanceof WorkspaceEInvoicing ? $bound : null;
        };
    }

    /** Wired after construction: the routes need the route subject, which needs this. */
    public function useApprovals(InvoiceApprovals $approvals, string $side = 'sale'): void
    {
        if ($side === 'purchase') {
            $this->billApprovals = $approvals;
        } else {
            $this->approvals = $approvals;
        }
    }

    /** `sale` or `purchase`, from a document's kind. */
    public static function sideOf(string $kind): string
    {
        return str_starts_with($kind, 'purchase') ? 'purchase' : 'sale';
    }

    // ── drafting ─────────────────────────────────────────────────────────

    /**
     * A draft sales invoice.
     *
     * @param array<string, mixed> $data `contact_id`, `issue_date`, `supply_date`, `due_date` or `payment_terms_days`,
     *     `prices_include_tax`, `notes`, `lines` (each `variant_id` or `description`, `quantity`, `unit`, `unit_price_minor`,
     *     `discount_bp`, `tax_code` (id or code) or `tax_category`, `account_id` or `account_code`, `ou_id`)
     * @param array{agent_id?: ?int, on_behalf_of?: ?int, external_ref?: ?string, owner_plugin?: ?string, quote_id?: ?int} $meta
     * @return array{invoice: array<string, mixed>, repeated: bool}
     */
    public function create(int $tenantId, ?int $actor, array $data, array $meta = []): array
    {
        $this->workspace->ensure($tenantId);
        $external = self::externalRef($meta['external_ref'] ?? null);
        if ($external !== null && ($done = $this->idByExternal($tenantId, $external)) !== null) {
            return ['invoice' => $this->get($tenantId, $done), 'repeated' => true];
        }
        $kind = $data['kind'] ?? 'sale';
        if (!in_array($kind, ['sale', 'purchase'], true)) {
            throw AccountingRejectedException::field('kind', 'kind is sale or purchase; credits are made from an issued document');
        }
        $head = $this->head($tenantId, $data, null, $kind);
        $lines = $this->lines($tenantId, $data['lines'] ?? null, $head['issue_date'], $head['prices_include_tax'], $kind);
        $supplierNumber = $kind === 'purchase' ? self::supplierNumber($data['supplier_number'] ?? null) : null;

        try {
            $id = $this->db->transaction(function () use ($tenantId, $actor, $head, $lines, $meta, $external, $kind, $supplierNumber): int {
                $id = $this->db->insert(
                    'INSERT INTO acc_invoices (tenant_id, kind, supplier_number, contact_id, issue_date, supply_date, due_date, payment_terms_days, status,
                            currency, prices_include_tax, notes, external_ref, quote_id, created_by_profile_id, agent_id,
                            on_behalf_of_profile_id, owner_plugin)
                     VALUES (:tenant_id, :kind, :supplier_number, :contact_id, :issue_date, :supply_date, :due_date, :terms, \'draft\',
                            :currency, :inclusive, :notes, :external, :quote_id, :actor, :agent, :on_behalf, :owner)',
                    [
                        ':kind' => $kind, ':supplier_number' => $supplierNumber,
                        ':tenant_id' => $tenantId, ':contact_id' => $head['contact_id'], ':issue_date' => $head['issue_date'],
                        ':supply_date' => $head['supply_date'], ':due_date' => $head['due_date'], ':terms' => $head['payment_terms_days'],
                        ':currency' => $head['currency'], ':inclusive' => $head['prices_include_tax'], ':notes' => $head['notes'],
                        ':external' => $external, ':quote_id' => AccountingDb::int($meta['quote_id'] ?? null), ':actor' => $actor,
                        ':agent' => AccountingDb::int($meta['agent_id'] ?? null), ':on_behalf' => AccountingDb::int($meta['on_behalf_of'] ?? null),
                        ':owner' => is_string($meta['owner_plugin'] ?? null) ? $meta['owner_plugin'] : null,
                    ]
                );
                $this->writeLines($tenantId, $id, $lines);

                return $id;
            });
        } catch (\PDOException $e) {
            if ($external !== null && AccountingDb::isUniqueViolation($e) && ($done = $this->idByExternal($tenantId, $external)) !== null) {
                return ['invoice' => $this->get($tenantId, $done), 'repeated' => true];
            }
            throw $e;
        }

        return ['invoice' => $this->get($tenantId, $id), 'repeated' => false];
    }

    /**
     * Changes a draft. Lines, when given, replace every line.
     *
     * @param array<string, mixed> $changes as {@see self::create()}
     * @return array<string, mixed>
     */
    public function update(int $tenantId, int $id, array $changes): array
    {
        $invoice = $this->row($tenantId, $id) ?? throw AccountingRejectedException::notFound('Invoice');
        if ($invoice['status'] !== 'draft' || !in_array($invoice['kind'], ['sale', 'purchase'], true)) {
            throw new AccountingRejectedException(
                $invoice['status'] === 'waiting_approval' ? 'This invoice is waiting for approval' : 'Only a draft invoice can be changed; credit an issued one instead',
                409
            );
        }
        $current = [
            'contact_id' => (int) $invoice['contact_id'], 'issue_date' => substr((string) $invoice['issue_date'], 0, 10),
            'supply_date' => is_string($invoice['supply_date']) ? substr($invoice['supply_date'], 0, 10) : null,
            'due_date' => is_string($invoice['due_date']) ? substr($invoice['due_date'], 0, 10) : null,
            'prices_include_tax' => DbBool::of($invoice['prices_include_tax']), 'notes' => (string) $invoice['notes'],
        ];
        if (array_key_exists('payment_terms_days', $changes) && !array_key_exists('due_date', $changes)) {
            $current['due_date'] = null;
        }
        $kind = (string) $invoice['kind'];
        $head = $this->head($tenantId, $changes + $current, $invoice, $kind);
        $lines = array_key_exists('lines', $changes)
            ? $this->lines($tenantId, $changes['lines'], $head['issue_date'], $head['prices_include_tax'], $kind)
            : $this->linesAgain($tenantId, $id, $head['issue_date'], $head['prices_include_tax']);
        $supplierNumber = $kind === 'purchase'
            ? (array_key_exists('supplier_number', $changes) ? self::supplierNumber($changes['supplier_number']) : (is_string($invoice['supplier_number']) ? $invoice['supplier_number'] : null))
            : null;

        $this->db->transaction(function () use ($tenantId, $id, $head, $lines, $supplierNumber): void {
            $this->db->run(
                'UPDATE acc_invoices SET contact_id = :contact_id, issue_date = :issue_date, supply_date = :supply_date, due_date = :due_date,
                        payment_terms_days = :terms, prices_include_tax = :inclusive, notes = :notes, supplier_number = :supplier_number,
                        updated_at = CURRENT_TIMESTAMP
                  WHERE tenant_id = :tenant_id AND id = :id AND status = \'draft\'',
                [
                    ':contact_id' => $head['contact_id'], ':issue_date' => $head['issue_date'], ':supply_date' => $head['supply_date'],
                    ':due_date' => $head['due_date'], ':terms' => $head['payment_terms_days'], ':inclusive' => $head['prices_include_tax'],
                    ':notes' => $head['notes'], ':supplier_number' => $supplierNumber, ':tenant_id' => $tenantId, ':id' => $id,
                ]
            );
            $this->db->run('DELETE FROM acc_invoice_lines WHERE tenant_id = :tenant_id AND invoice_id = :id', [':tenant_id' => $tenantId, ':id' => $id]);
            $this->writeLines($tenantId, $id, $lines);
        });

        return $this->get($tenantId, $id);
    }

    /**
     * Cancels a draft, or one waiting for approval. An issued invoice is credited, never cancelled.
     *
     * @return array<string, mixed>
     */
    public function cancel(int $tenantId, int $id, ?int $actor): array
    {
        $invoice = $this->row($tenantId, $id) ?? throw AccountingRejectedException::notFound('Invoice');
        if (!in_array($invoice['status'], ['draft', 'waiting_approval'], true)) {
            throw new AccountingRejectedException(
                $invoice['status'] === 'cancelled' ? 'This invoice is already cancelled' : 'An issued invoice is never cancelled; make a credit note for it',
                409
            );
        }
        $this->db->run(
            'UPDATE acc_invoices SET status = \'cancelled\', updated_at = CURRENT_TIMESTAMP
              WHERE tenant_id = :tenant_id AND id = :id AND status IN (\'draft\', \'waiting_approval\')',
            [':tenant_id' => $tenantId, ':id' => $id]
        );

        return $this->get($tenantId, $id);
    }

    // ── issuing ──────────────────────────────────────────────────────────

    /**
     * Issues a draft, or sends it for approval when the workspace has an
     * invoice route.
     *
     * @return array<string, mixed>
     */
    public function issue(int $tenantId, int $id, ?int $actor, bool $route = true): array
    {
        $invoice = $this->row($tenantId, $id) ?? throw AccountingRejectedException::notFound('Invoice');
        if ($invoice['status'] !== 'draft') {
            throw new AccountingRejectedException(match ($invoice['status']) {
                'waiting_approval' => 'This invoice is waiting for approval',
                'cancelled' => 'This invoice was cancelled',
                default => 'This invoice is already issued',
            }, 409);
        }
        $this->ready($tenantId, $id);
        $approvals = !$route ? null : (self::sideOf((string) $invoice['kind']) === 'purchase' ? $this->billApprovals : $this->approvals);
        if ($approvals !== null) {
            $view = $this->get($tenantId, $id);
            $routeId = $approvals->issue($tenantId, $actor, $view);
            if ($routeId !== null) {
                $this->db->run(
                    'UPDATE acc_invoices SET status = \'waiting_approval\', route_id = :route_id, updated_at = CURRENT_TIMESTAMP
                      WHERE tenant_id = :tenant_id AND id = :id AND status = \'draft\'',
                    [':route_id' => $routeId, ':tenant_id' => $tenantId, ':id' => $id]
                );

                return $this->get($tenantId, $id);
            }
        }

        return $this->issueNow($tenantId, $id, $actor);
    }

    /** The route approved it: issue it now, as the person who decided. */
    public function approve(int $tenantId, int $id, ?int $by): void
    {
        $invoice = $this->row($tenantId, $id) ?? throw AccountingRejectedException::notFound('Invoice');
        if ($invoice['status'] !== 'waiting_approval') {
            return;
        }
        $this->issueNow($tenantId, $id, $by);
    }

    /** The route turned it down or sent it back: a draft again. */
    public function rejected(int $tenantId, int $id): void
    {
        $this->db->run(
            'UPDATE acc_invoices SET status = \'draft\', route_id = NULL, updated_at = CURRENT_TIMESTAMP
              WHERE tenant_id = :tenant_id AND id = :id AND status = \'waiting_approval\'',
            [':tenant_id' => $tenantId, ':id' => $id]
        );
    }

    /**
     * A credit note for an issued invoice, issued at once: every line, or
     * `lines` as `[{line_id, quantity}]` for part of it.
     *
     * @param array<string, mixed> $data `lines`, `reason`, `issue_date`
     * @param array{agent_id?: ?int, on_behalf_of?: ?int, external_ref?: ?string, owner_plugin?: ?string} $meta
     * @return array{invoice: array<string, mixed>, repeated: bool}
     */
    public function credit(int $tenantId, int $invoiceId, ?int $actor, array $data, array $meta = []): array
    {
        $external = self::externalRef($meta['external_ref'] ?? null);
        if ($external !== null && ($done = $this->idByExternal($tenantId, $external)) !== null) {
            return ['invoice' => $this->get($tenantId, $done), 'repeated' => true];
        }
        $original = $this->row($tenantId, $invoiceId) ?? throw AccountingRejectedException::notFound('Invoice');
        if (!in_array($original['kind'], ['sale', 'purchase'], true) || !in_array($original['status'], ['issued', 'partly_paid', 'paid'], true)) {
            throw new AccountingRejectedException(
                !in_array($original['kind'], ['sale', 'purchase'], true) ? 'A credit is made from an invoice or a bill, not from another credit' : 'Only an issued invoice or a posted bill can be credited; change or cancel a draft instead',
                409
            );
        }
        $creditKind = $original['kind'] . '_credit';
        $date = isset($data['issue_date']) ? Periods::date($data['issue_date'], 'issue_date') : $this->today();
        if ($date < substr((string) $original['issue_date'], 0, 10)) {
            throw AccountingRejectedException::field('issue_date', 'A credit note cannot be dated before its invoice (' . substr((string) $original['issue_date'], 0, 10) . ')');
        }
        $reason = is_string($data['reason'] ?? null) ? mb_substr(trim($data['reason']), 0, 2000) : '';
        $lines = $this->creditLines($tenantId, $invoiceId, $data['lines'] ?? null, DbBool::of($original['prices_include_tax']));

        $id = $this->db->transaction(function () use ($tenantId, $actor, $original, $invoiceId, $date, $reason, $lines, $meta, $external, $creditKind): int {
            $id = $this->db->insert(
                'INSERT INTO acc_invoices (tenant_id, kind, contact_id, issue_date, supply_date, due_date, status, currency,
                        prices_include_tax, credits_invoice_id, notes, external_ref, created_by_profile_id, agent_id,
                        on_behalf_of_profile_id, owner_plugin)
                 VALUES (:tenant_id, :kind, :contact_id, :issue_date, :supply_date, :issue_date2, \'draft\', :currency,
                        :inclusive, :credits, :notes, :external, :actor, :agent, :on_behalf, :owner)',
                [
                    ':tenant_id' => $tenantId, ':kind' => $creditKind, ':contact_id' => (int) $original['contact_id'], ':issue_date' => $date,
                    ':supply_date' => $date, ':issue_date2' => $date, ':currency' => (string) $original['currency'],
                    ':inclusive' => DbBool::of($original['prices_include_tax']), ':credits' => $invoiceId, ':notes' => $reason,
                    ':external' => $external, ':actor' => $actor, ':agent' => AccountingDb::int($meta['agent_id'] ?? null),
                    ':on_behalf' => AccountingDb::int($meta['on_behalf_of'] ?? null),
                    ':owner' => is_string($meta['owner_plugin'] ?? null) ? $meta['owner_plugin'] : null,
                ]
            );
            $this->writeLines($tenantId, $id, $lines);
            $this->issueNow($tenantId, $id, $actor);

            return $id;
        });
        $this->afterIssue($tenantId, $id);

        return ['invoice' => $this->get($tenantId, $id), 'repeated' => false];
    }

    /**
     * Numbers, freezes and posts. Inside the caller's transaction when there
     * is one; e-invoicing runs after commit.
     *
     * @return array<string, mixed>
     */
    private function issueNow(int $tenantId, int $id, ?int $actor): array
    {
        $this->ready($tenantId, $id);
        $view = $this->get($tenantId, $id);
        $credit = str_ends_with((string) $view['kind'], '_credit');
        $side = self::SIDES[self::sideOf((string) $view['kind'])];
        try {
            $this->hooks?->dispatch($side['noun'] === 'bill' ? Events::ACCOUNTING_BILL_POSTING : Events::ACCOUNTING_INVOICE_ISSUING, $this->payload($tenantId, $view, $actor) + [
                'lines' => array_map(static fn (array $l): array => [
                    'variant_id' => $l['variant_id'], 'description' => $l['description'], 'quantity' => $l['quantity'],
                    'unit_price_minor' => $l['unit_price_minor'], 'net_minor' => $l['net_minor'], 'tax_code' => $l['tax_code'],
                    'tax_minor' => $l['tax_minor'], 'total_minor' => $l['total_minor'],
                ], $view['lines']),
            ]);
        } catch (HookVetoException $e) {
            throw AccountingRejectedException::coded('vetoed', $e->reason(), 409);
        }

        $outer = $this->db->inTransaction();
        $this->db->transaction(function () use ($tenantId, $id, $actor, $view, $credit, $side): void {
            $year = substr((string) $view['issue_date'], 0, 4);
            $number = sprintf($side['prefix'][$credit ? 1 : 0] . '-%s-%04d', $year, $this->sequences->next($tenantId, 'acc_invoices:' . $view['kind'] . ':' . $year));
            $claimed = $this->db->run(
                'UPDATE acc_invoices SET status = \'issued\', number = :number, issued_at = CURRENT_TIMESTAMP, issued_by_profile_id = :actor,
                        updated_at = CURRENT_TIMESTAMP
                  WHERE tenant_id = :tenant_id AND id = :id AND status IN (\'draft\', \'waiting_approval\')',
                [':number' => $number, ':actor' => $actor, ':tenant_id' => $tenantId, ':id' => $id]
            )->rowCount();
            if ($claimed !== 1) {
                throw new AccountingRejectedException('This invoice was issued or changed at the same moment; open it again', 409, ['code' => 'conflict']);
            }
            $posted = $this->ledger->post($tenantId, $actor, [
                'journal' => $side['journal'],
                'date' => (string) $view['issue_date'],
                'memo' => match (true) {
                    $side['noun'] === 'bill' && $credit => 'Supplier credit ',
                    $side['noun'] === 'bill' => 'Bill ',
                    $credit => 'Credit note ',
                    default => 'Invoice ',
                } . $number . ($view['supplier_number'] !== null ? ' (' . $view['supplier_number'] . ')' : '') . ' · ' . $view['contact_name'],
                'source_type' => $side['noun'] === 'bill' ? ($credit ? 'supplier_credit' : 'bill') : ($credit ? 'credit_note' : 'invoice'),
                'source_id' => $id,
                'idempotency_key' => 'invoice:' . $id,
                'agent_id' => $view['agent_id'],
                'on_behalf_of' => $view['on_behalf_of_profile_id'],
                'lines' => $this->entryLines($tenantId, $view, $credit, $side),
            ]);
            $this->db->run(
                'UPDATE acc_invoices SET entry_id = :entry_id WHERE tenant_id = :tenant_id AND id = :id',
                [':entry_id' => (int) $posted['entry']['id'], ':tenant_id' => $tenantId, ':id' => $id]
            );
            $this->refresh($tenantId, $id);
            if ($credit && $view['credits_invoice_id'] !== null) {
                $this->settleWithCredit($tenantId, (int) $view['credits_invoice_id'], $id, (string) $view['issue_date'], $actor);
            }
            $this->notify($side['noun'] === 'bill' ? Events::ACCOUNTING_BILL_POSTED : Events::ACCOUNTING_INVOICE_ISSUED, $tenantId, $id, $actor);
        });
        if (!$outer) {
            $this->afterIssue($tenantId, $id);
        }

        return $this->get($tenantId, $id);
    }

    /**
     * The entry's lines: the receivable, each line's income, each code's tax.
     *
     * A sale debits the customer and credits income and tax; a bill
     * credits the supplier and debits costs and the tax to reclaim. Credits
     * mirror both.
     *
     * @param array<string, mixed> $view
     * @param array{counter: string, tax: string, noun: string} $doc
     * @return list<array<string, mixed>>
     */
    private function entryLines(int $tenantId, array $view, bool $credit, array $doc): array
    {
        $side = static fn (int $amount, bool $debit): array => $debit
            ? ['debit_minor' => $amount, 'credit_minor' => 0]
            : ['debit_minor' => 0, 'credit_minor' => $amount];
        $purchase = $doc['noun'] === 'bill';
        // The counterpart (who owes or is owed) is debited on a sale and on a supplier credit.
        $counterDebit = $purchase ? $credit : !$credit;
        $sign = $credit ? -1 : 1;
        $contactId = (int) $view['contact_id'];
        $lines = [[
            'account_id' => $this->workspace->roleAccountId($tenantId, $doc['counter']),
            'contact_id' => $contactId,
            'description' => (string) $view['contact_name'],
        ] + $side((int) $view['total_minor'], $counterDebit)];
        $taxByCode = [];
        foreach ($view['lines'] as $l) {
            if ((int) $l['net_minor'] > 0) {
                $lines[] = [
                    'account_id' => (int) $l['account_id'],
                    'contact_id' => $contactId,
                    'ou_id' => $l['ou_id'],
                    'description' => mb_substr((string) $l['description'], 0, 500),
                    'tax_code_id' => (int) $l['tax_code_id'],
                    'tax_base_minor' => $sign * (int) $l['net_minor'],
                    'source_line_type' => 'invoice_line',
                    'source_line_id' => (int) $l['id'],
                ] + $side((int) $l['net_minor'], !$counterDebit);
            }
            if ((int) $l['tax_minor'] > 0) {
                $taxByCode[(int) $l['tax_code_id']] = ($taxByCode[(int) $l['tax_code_id']] ?? 0) + (int) $l['tax_minor'];
            }
        }
        ksort($taxByCode);
        foreach ($taxByCode as $codeId => $tax) {
            $code = $this->taxes->get($tenantId, $codeId);
            $lines[] = [
                'account_id' => $code['account_id'] ?? $this->workspace->roleAccountId($tenantId, $doc['tax']),
                'contact_id' => $contactId,
                'description' => $code['name'],
                'tax_code_id' => $codeId,
            ] + $side($tax, !$counterDebit);
        }

        return $lines;
    }

    /** A credit note settles what its invoice still owes; the rest stays as the customer's credit. */
    private function settleWithCredit(int $tenantId, int $invoiceId, int $creditId, string $date, ?int $actor): void
    {
        $invoice = $this->row($tenantId, $invoiceId) ?? throw AccountingRejectedException::notFound('Invoice');
        $credit = $this->row($tenantId, $creditId) ?? throw AccountingRejectedException::notFound('Credit note');
        $owed = (int) $invoice['total_minor'] - (int) $invoice['paid_minor'] - (int) $invoice['credited_minor'];
        $amount = min($owed, (int) $credit['total_minor']);
        if ($amount > 0) {
            $this->db->run(
                'INSERT INTO acc_allocations (tenant_id, invoice_id, credit_invoice_id, amount_minor, allocated_on, created_by_profile_id)
                 VALUES (:tenant_id, :invoice_id, :credit_id, :amount, :date, :actor)',
                [':tenant_id' => $tenantId, ':invoice_id' => $invoiceId, ':credit_id' => $creditId, ':amount' => $amount, ':date' => $date, ':actor' => $actor]
            );
        }
        $this->refresh($tenantId, $invoiceId);
        $this->refresh($tenantId, $creditId);
        $this->notify(Events::ACCOUNTING_INVOICE_CREDITED, $tenantId, $invoiceId, $actor, ['credit_note_id' => $creditId, 'amount_minor' => $amount]);
    }

    /**
     * `paid_minor`, `credited_minor` and the status, from the allocations.
     * A credit note is settled by what it settled.
     */
    public function refresh(int $tenantId, int $id): void
    {
        $row = $this->row($tenantId, $id);
        if ($row === null || !in_array($row['status'], ['issued', 'partly_paid', 'paid', 'credited'], true)) {
            return;
        }
        if (str_ends_with((string) $row['kind'], '_credit')) {
            $used = (int) ($this->db->one(
                'SELECT COALESCE(SUM(amount_minor), 0) AS used FROM acc_allocations
                  WHERE tenant_id = :tenant_id AND credit_invoice_id = :id AND reversed_at IS NULL',
                [':tenant_id' => $tenantId, ':id' => $id]
            )['used'] ?? 0);
            $this->db->run(
                'UPDATE acc_invoices SET credited_minor = :used, updated_at = CURRENT_TIMESTAMP WHERE tenant_id = :tenant_id AND id = :id',
                [':used' => $used, ':tenant_id' => $tenantId, ':id' => $id]
            );

            return;
        }
        $sums = $this->db->one(
            'SELECT COALESCE(SUM(CASE WHEN payment_id IS NOT NULL THEN amount_minor ELSE 0 END), 0) AS paid,
                    COALESCE(SUM(CASE WHEN credit_invoice_id IS NOT NULL THEN amount_minor ELSE 0 END), 0) AS credited
               FROM acc_allocations WHERE tenant_id = :tenant_id AND invoice_id = :id AND reversed_at IS NULL',
            [':tenant_id' => $tenantId, ':id' => $id]
        ) ?? ['paid' => 0, 'credited' => 0];
        $paid = (int) $sums['paid'];
        $credited = (int) $sums['credited'];
        $total = (int) $row['total_minor'];
        $status = match (true) {
            $paid + $credited < $total && $paid + $credited > 0 => 'partly_paid',
            $paid + $credited < $total => 'issued',
            $paid === 0 => 'credited',
            default => 'paid',
        };
        $this->db->run(
            'UPDATE acc_invoices SET paid_minor = :paid, credited_minor = :credited, status = :status, updated_at = CURRENT_TIMESTAMP
              WHERE tenant_id = :tenant_id AND id = :id',
            [':paid' => $paid, ':credited' => $credited, ':status' => $status, ':tenant_id' => $tenantId, ':id' => $id]
        );
    }

    /** After commit: report to the tax authority when a connector is bound and on. */
    private function afterIssue(int $tenantId, int $id): void
    {
        $row = $this->row($tenantId, $id);
        if ($row === null || $row['number'] === null || $row['einvoice_status'] !== 'none' || self::sideOf((string) $row['kind']) !== 'sale') {
            return;
        }
        $connector = ($this->einvoicing)();
        try {
            $on = $connector !== null && $connector->isOn();
        } catch (\Throwable $e) {
            error_log('[accounting] e-invoicing could not say whether it is on: ' . $e->getMessage());
            $on = false;
        }
        if (!$on || $connector === null) {
            $this->einvoice($tenantId, $id, 'not_required', new EInvoiceResult('pending'));

            return;
        }
        try {
            $result = $connector->submit($this->snapshot($tenantId, $id));
            $this->einvoice($tenantId, $id, $result->status, $result);
        } catch (\Throwable $e) {
            error_log('[accounting] e-invoicing could not report invoice ' . $id . ': ' . $e->getMessage());
            $this->einvoice($tenantId, $id, 'pending', EInvoiceResult::pending());
        }
    }

    private function einvoice(int $tenantId, int $id, string $status, EInvoiceResult $result): void
    {
        $this->db->run(
            'UPDATE acc_invoices SET einvoice_status = :status, einvoice_uuid = :uuid, einvoice_ref = :ref, einvoice_qr = :qr,
                    einvoice_counter = :counter, einvoice_message = :message, updated_at = CURRENT_TIMESTAMP
              WHERE tenant_id = :tenant_id AND id = :id',
            [
                ':status' => $status, ':uuid' => $result->uuid, ':ref' => $result->reference, ':qr' => $result->qr,
                ':counter' => $result->counter, ':message' => mb_substr($result->message, 0, 500), ':tenant_id' => $tenantId, ':id' => $id,
            ]
        );
    }

    // ── reading ──────────────────────────────────────────────────────────

    /**
     * @param array{side?: string, kind?: string, status?: string, contact_id?: int, q?: string, from?: string, to?: string} $filter
     * @return list<array<string, mixed>>
     */
    public function list(int $tenantId, array $filter = [], int $limit = 50, int $offset = 0): array
    {
        $this->workspace->ensure($tenantId);
        $where = ['i.tenant_id = :tenant_id'];
        $params = [':tenant_id' => $tenantId];
        $kind = $filter['kind'] ?? null;
        if ($kind !== null) {
            if (!in_array($kind, [...self::SALE_KINDS, ...self::PURCHASE_KINDS], true)) {
                throw AccountingRejectedException::field('kind', 'kind is sale, sale_credit, purchase or purchase_credit');
            }
            $where[] = 'i.kind = :kind';
            $params[':kind'] = $kind;
        } else {
            $where[] = ($filter['side'] ?? 'sale') === 'purchase' ? 'i.kind IN (\'purchase\', \'purchase_credit\')' : 'i.kind IN (\'sale\', \'sale_credit\')';
        }
        if (isset($filter['status'])) {
            if ($filter['status'] === 'overdue') {
                $where[] = 'i.status IN (\'issued\', \'partly_paid\') AND i.due_date < :today';
                $params[':today'] = $this->today();
            } elseif (in_array($filter['status'], self::STATUSES, true)) {
                $where[] = 'i.status = :status';
                $params[':status'] = $filter['status'];
            } else {
                throw AccountingRejectedException::field('status', 'status is one of ' . implode(', ', self::STATUSES) . ', or overdue');
            }
        }
        if (isset($filter['contact_id'])) {
            $where[] = 'i.contact_id = :contact_id';
            $params[':contact_id'] = (int) $filter['contact_id'];
        }
        if (isset($filter['from'])) {
            $where[] = 'i.issue_date >= :from';
            $params[':from'] = Periods::date($filter['from'], 'from');
        }
        if (isset($filter['to'])) {
            $where[] = 'i.issue_date <= :to';
            $params[':to'] = Periods::date($filter['to'], 'to');
        }
        if (isset($filter['q']) && trim($filter['q']) !== '') {
            $where[] = '(LOWER(i.number) LIKE :q OR LOWER(c.name) LIKE :q2)';
            $params[':q'] = '%' . mb_strtolower(trim($filter['q'])) . '%';
            $params[':q2'] = $params[':q'];
        }
        $rows = $this->db->all(
            'SELECT ' . self::COLUMNS . ', c.name AS contact_name
               FROM acc_invoices i JOIN contacts c ON c.id = i.contact_id AND c.tenant_id = i.tenant_id
              WHERE ' . implode(' AND ', $where) . '
              ORDER BY i.issue_date DESC, i.id DESC
              LIMIT ' . max(1, min(200, $limit)) . ' OFFSET ' . max(0, $offset),
            $params
        );

        return array_map(fn (array $r): array => $this->present($r), $rows);
    }

    /** @return array<string, mixed> */
    public function get(int $tenantId, int $id): array
    {
        $this->workspace->ensure($tenantId);
        $row = $this->db->one(
            'SELECT ' . self::COLUMNS . ', c.name AS contact_name
               FROM acc_invoices i JOIN contacts c ON c.id = i.contact_id AND c.tenant_id = i.tenant_id
              WHERE i.tenant_id = :tenant_id AND i.id = :id',
            [':tenant_id' => $tenantId, ':id' => $id]
        ) ?? throw AccountingRejectedException::notFound('Invoice');
        $lines = $this->db->all(
            'SELECT l.id, l.line_no, l.variant_id, l.unit_id, l.description, l.quantity, l.quantity_decimals, l.unit_price_minor,
                    l.discount_bp, l.net_minor, l.tax_code_id, t.code AS tax_code, t.rate_bp AS tax_rate_bp, l.tax_minor, l.total_minor,
                    l.account_id, a.code AS account_code, a.name AS account_name, l.ou_id, l.credits_line_id
               FROM acc_invoice_lines l
               JOIN acc_tax_codes t ON t.id = l.tax_code_id AND t.tenant_id = l.tenant_id
               JOIN acc_accounts a ON a.id = l.account_id AND a.tenant_id = l.tenant_id
              WHERE l.tenant_id = :tenant_id AND l.invoice_id = :id
              ORDER BY l.line_no',
            [':tenant_id' => $tenantId, ':id' => $id]
        );
        $allocations = $this->db->all(
            'SELECT a.id, a.invoice_id, a.credit_invoice_id, a.payment_id, a.amount_minor, a.allocated_on, ci.number AS credit_number
               FROM acc_allocations a LEFT JOIN acc_invoices ci ON ci.id = a.credit_invoice_id AND ci.tenant_id = a.tenant_id
              WHERE a.tenant_id = :tenant_id AND (a.invoice_id = :id OR a.credit_invoice_id = :id2) AND a.reversed_at IS NULL
              ORDER BY a.id',
            [':tenant_id' => $tenantId, ':id' => $id, ':id2' => $id]
        );

        return $this->present($row) + [
            'lines' => array_map(static fn (array $l): array => [
                'id' => (int) $l['id'],
                'line_no' => (int) $l['line_no'],
                'variant_id' => AccountingDb::int($l['variant_id']),
                'unit_id' => AccountingDb::int($l['unit_id']),
                'description' => (string) $l['description'],
                'quantity' => (int) $l['quantity'],
                'quantity_decimals' => (int) $l['quantity_decimals'],
                'unit_price_minor' => (int) $l['unit_price_minor'],
                'discount_bp' => (int) $l['discount_bp'],
                'net_minor' => (int) $l['net_minor'],
                'tax_code_id' => (int) $l['tax_code_id'],
                'tax_code' => (string) $l['tax_code'],
                'tax_rate_bp' => (int) $l['tax_rate_bp'],
                'tax_minor' => (int) $l['tax_minor'],
                'total_minor' => (int) $l['total_minor'],
                'account_id' => (int) $l['account_id'],
                'account_code' => (string) $l['account_code'],
                'account_name' => (string) $l['account_name'],
                'ou_id' => AccountingDb::int($l['ou_id']),
                'credits_line_id' => AccountingDb::int($l['credits_line_id']),
            ], $lines),
            'allocations' => array_map(static fn (array $a): array => [
                'id' => (int) $a['id'],
                'invoice_id' => (int) $a['invoice_id'],
                'credit_invoice_id' => AccountingDb::int($a['credit_invoice_id']),
                'credit_number' => is_string($a['credit_number']) ? $a['credit_number'] : null,
                'payment_id' => AccountingDb::int($a['payment_id']),
                'amount_minor' => (int) $a['amount_minor'],
                'allocated_on' => substr((string) $a['allocated_on'], 0, 10),
            ], $allocations),
        ];
    }

    /** @return array<string, mixed>|null the stored row */
    public function row(int $tenantId, int $id): ?array
    {
        return $this->db->one(
            'SELECT ' . self::COLUMNS . ' FROM acc_invoices i WHERE i.tenant_id = :tenant_id AND i.id = :id',
            [':tenant_id' => $tenantId, ':id' => $id]
        );
    }

    /** The SDK's read-only view. */
    public function snapshot(int $tenantId, int $id): Invoice
    {
        return Invoice::fromArray($this->get($tenantId, $id));
    }

    /** Sales invoices issued in [$from, $until), for the invoices meter (BM.3, AC.29). Credit notes don't count, nor take one away. */
    public function issuedBetween(int $tenantId, \DateTimeImmutable $from, \DateTimeImmutable $until): int
    {
        return (int) ($this->db->one(
            'SELECT COUNT(*) AS n FROM acc_invoices
              WHERE tenant_id = :tenant_id AND kind = \'sale\' AND issued_at IS NOT NULL AND issued_at >= :from AND issued_at < :until',
            [':tenant_id' => $tenantId, ':from' => $from->format('Y-m-d H:i:s'), ':until' => $until->format('Y-m-d H:i:s')]
        )['n'] ?? 0);
    }

    /** Whether the books mention a contact: an invoice, or a posted line. */
    public function mentions(int $tenantId, int $contactId): bool
    {
        return $this->db->one(
            'SELECT 1 AS hit FROM acc_invoices WHERE tenant_id = :tenant_id AND contact_id = :contact_id
             UNION ALL
             SELECT 1 AS hit FROM acc_lines WHERE tenant_id = :tenant_id2 AND contact_id = :contact_id2
             LIMIT 1',
            [':tenant_id' => $tenantId, ':contact_id' => $contactId, ':tenant_id2' => $tenantId, ':contact_id2' => $contactId]
        ) !== null;
    }

    // ── checking ─────────────────────────────────────────────────────────

    /**
     * @param array<string, mixed> $data
     * @param array<string, mixed>|null $existing
     * @return array{contact_id: int, issue_date: string, supply_date: ?string, due_date: ?string, payment_terms_days: int, currency: string, prices_include_tax: bool, notes: string}
     */
    private function head(int $tenantId, array $data, ?array $existing, string $kind = 'sale'): array
    {
        $side = self::SIDES[self::sideOf($kind)];
        $contactId = $data['contact_id'] ?? null;
        $contact = is_int($contactId) ? $this->db->one(
            'SELECT id, name, is_customer, is_supplier, archived_at FROM contacts WHERE tenant_id = :tenant_id AND id = :id',
            [':tenant_id' => $tenantId, ':id' => $contactId]
        ) : null;
        if ($contact === null || $contact['archived_at'] !== null) {
            throw AccountingRejectedException::field('contact_id', 'contact_id is a ' . $side['who'] . ' here');
        }
        if (!DbBool::of($contact[$side['flag']])) {
            throw AccountingRejectedException::field('contact_id', $contact['name'] . ' is not marked as a ' . $side['who'] . '; mark them as one on their contact page first');
        }
        $settings = ($this->settings)($tenantId);
        $issue = isset($data['issue_date']) && $data['issue_date'] !== '' ? Periods::date($data['issue_date'], 'issue_date') : $this->today();
        $supply = isset($data['supply_date']) && $data['supply_date'] !== '' ? Periods::date($data['supply_date'], 'supply_date') : null;
        $terms = $data['payment_terms_days'] ?? ($existing !== null ? (int) $existing['payment_terms_days'] : (int) ($settings[SettingsRegistry::ACCOUNTING_INVOICE_PAYMENT_TERMS_DAYS] ?? 0));
        if (!is_int($terms) || $terms < 0 || $terms > 365) {
            throw AccountingRejectedException::field('payment_terms_days', 'payment_terms_days is 0 to 365');
        }
        $due = isset($data['due_date']) && $data['due_date'] !== ''
            ? Periods::date($data['due_date'], 'due_date')
            : (new \DateTimeImmutable($issue))->modify('+' . $terms . ' days')->format('Y-m-d');
        if ($due < $issue) {
            throw AccountingRejectedException::field('due_date', 'The due date cannot be before the issue date');
        }
        $inclusive = $data['prices_include_tax'] ?? ($settings[SettingsRegistry::ACCOUNTING_PRICES_INCLUDE_TAX] ?? 'false') === 'true';
        if (!is_bool($inclusive)) {
            throw AccountingRejectedException::field('prices_include_tax', 'prices_include_tax is true or false');
        }
        $currency = $this->workspace->books($tenantId)['base_currency'];
        if (isset($data['currency']) && $data['currency'] !== '' && strtoupper((string) $data['currency']) !== $currency) {
            throw AccountingRejectedException::field('currency', 'Invoices are in the books\' currency, ' . $currency . ', until invoices in other currencies arrive');
        }

        return [
            'contact_id' => (int) $contact['id'],
            'issue_date' => $issue,
            'supply_date' => $supply,
            'due_date' => $due,
            'payment_terms_days' => $terms,
            'currency' => $currency,
            'prices_include_tax' => $inclusive,
            'notes' => is_string($data['notes'] ?? null) ? mb_substr(trim($data['notes']), 0, 2000) : '',
        ];
    }

    /**
     * Lines as given, checked, priced and taxed.
     *
     * @return list<array<string, mixed>>
     */
    private function lines(int $tenantId, mixed $raw, string $date, bool $inclusive, string $kind = 'sale'): array
    {
        if (!is_array($raw) || !array_is_list($raw) || $raw === [] || count($raw) > self::MAX_LINES) {
            throw AccountingRejectedException::field('lines', 'lines is a list of 1 to ' . self::MAX_LINES . ' {variant_id or description, quantity, unit_price_minor}');
        }
        $out = [];
        foreach ($raw as $i => $line) {
            $field = 'lines[' . $i . ']';
            if (!is_array($line)) {
                throw AccountingRejectedException::field($field, $field . ' must be an object');
            }
            /** @var array<string, mixed> $line */
            $out[] = $this->line($tenantId, $field, $line, $date, $inclusive, self::sideOf($kind));
        }

        return $out;
    }

    /**
     * @param array<string, mixed> $line
     * @return array<string, mixed>
     */
    private function line(int $tenantId, string $field, array $line, string $date, bool $inclusive, string $sideName = 'sale'): array
    {
        $side = self::SIDES[$sideName];
        $quantity = is_string($line['quantity'] ?? null) || is_int($line['quantity'] ?? null) ? trim((string) $line['quantity']) : '1';
        $description = is_string($line['description'] ?? null) ? mb_substr(trim($line['description']), 0, 500) : '';
        $price = $line['unit_price_minor'] ?? $line['price_minor'] ?? null;
        $category = is_string($line['tax_category'] ?? null) ? $line['tax_category'] : '';
        $variantId = $line['variant_id'] ?? null;
        $serviceItem = false;
        if ($variantId !== null) {
            $item = $this->itemLine($tenantId, $field, $variantId, $line, $quantity, $sideName);
            $description = $description !== '' ? $description : $item['description'];
            $price ??= $item['price_minor'];
            $category = isset($line['tax_category']) ? $category : $item['tax_category'];
            $serviceItem = $item['service'];
            $steps = $item['quantity'];
            $decimals = $item['decimals'];
            $unitId = $item['unit_id'];
        } else {
            if ($description === '') {
                throw AccountingRejectedException::field($field, $field . ' needs an item (variant_id) or a description');
            }
            try {
                $q = Quantity::parse($quantity, self::FREE_DECIMALS);
            } catch (\InvalidArgumentException) {
                throw AccountingRejectedException::field($field, $field . '.quantity is a number with up to 3 decimals, such as 2.5');
            }
            if ($q->steps <= 0) {
                throw AccountingRejectedException::field($field, $field . '.quantity must be more than 0');
            }
            $steps = $q->steps;
            $decimals = self::FREE_DECIMALS;
            $unitId = null;
        }
        $price ??= 0;
        if (!is_int($price) || $price < 0) {
            throw AccountingRejectedException::field($field, $field . '.unit_price_minor is the price of one, in minor units, 0 or more');
        }
        $discount = $line['discount_bp'] ?? 0;
        if (!is_int($discount) || $discount < 0 || $discount > 10000) {
            throw AccountingRejectedException::field($field, $field . '.discount_bp is basis points, 0 to 10000');
        }
        $code = isset($line['tax_code']) && $line['tax_code'] !== ''
            ? $this->taxes->usable($tenantId, $line['tax_code'], $side['direction'], $date, $field . '.tax_code')
            : ($this->taxes->codeFor($tenantId, $category, $side['direction'], $date)
                ?? throw AccountingRejectedException::field($field, $field . ': no tax code fits the tax category "' . $category . '"; choose a tax_code'));
        $accountId = $sideName === 'purchase'
            ? $this->costAccount($tenantId, $line['account_id'] ?? null, $line['account_code'] ?? null, $field)
            : $this->incomeAccount($tenantId, $line['account_id'] ?? null, $line['account_code'] ?? null, $serviceItem, $field);
        $ouId = $line['ou_id'] ?? null;
        if ($ouId !== null && (!is_int($ouId) || $this->db->one(
            'SELECT 1 AS hit FROM organizational_units WHERE tenant_id = :tenant_id AND id = :id',
            [':tenant_id' => $tenantId, ':id' => $ouId]
        ) === null)) {
            throw AccountingRejectedException::field($field, $field . '.ou_id is not a unit here');
        }
        $amount = Taxes::lineAmount($steps, $decimals, $price, $discount);
        $split = Taxes::split($amount, (int) $code['rate_bp'], $inclusive);

        return [
            'variant_id' => is_int($variantId) ? $variantId : null,
            'unit_id' => $unitId,
            'description' => $description,
            'quantity' => $steps,
            'quantity_decimals' => $decimals,
            'unit_price_minor' => $price,
            'discount_bp' => $discount,
            'tax_code_id' => (int) $code['id'],
            'net_minor' => $split['net'],
            'tax_minor' => $split['tax'],
            'total_minor' => $split['total'],
            'account_id' => $accountId,
            'ou_id' => $ouId,
            'credits_line_id' => null,
        ];
    }

    /**
     * @param array<string, mixed> $line
     * @return array{description: string, price_minor: int, tax_category: string, service: bool, quantity: int, decimals: int, unit_id: int}
     */
    private function itemLine(int $tenantId, string $field, mixed $variantId, array $line, string $quantity, string $sideName = 'sale'): array
    {
        $found = is_int($variantId) ? $this->items->variantForStock($tenantId, $variantId) : null;
        if ($found === null || $found['item']['archived_at'] !== null || $found['variant']['archived_at'] !== null) {
            throw AccountingRejectedException::field($field, $field . '.variant_id is not an item here');
        }
        $itemId = (int) $found['item']['id'];
        $unitCode = is_string($line['unit'] ?? null) && $line['unit'] !== '' ? $line['unit'] : (string) $found['unit']['code'];
        try {
            $steps = $this->units->toBaseSteps($tenantId, $itemId, $found['unit'], $quantity, $unitCode, $field . '.quantity');
        } catch (InventoryRejectedException $e) {
            throw new AccountingRejectedException($e->clientMessage, $e->status, $e->extra);
        }
        if ($steps['steps'] <= 0) {
            throw AccountingRejectedException::field($field, $field . '.quantity must be more than 0');
        }
        $priceColumn = $sideName === 'purchase' ? 'purchase_price_minor' : 'sale_price_minor';
        $variantPrice = AccountingDb::int($this->db->one(
            'SELECT ' . $priceColumn . ' AS price FROM inv_variants WHERE tenant_id = :tenant_id AND id = :id',
            [':tenant_id' => $tenantId, ':id' => (int) $variantId]
        )['price'] ?? null);
        $default = $steps['unit_id'] === (int) $found['unit']['id']
            ? ($variantPrice ?? AccountingDb::int($found['item'][$priceColumn] ?? null) ?? 0)
            : 0;

        return [
            'description' => (string) $found['item']['name'],
            'price_minor' => $default,
            'tax_category' => (string) ($found['item']['tax_category'] ?? ''),
            'service' => ($found['item']['kind'] ?? '') === 'service',
            'quantity' => $steps['entered_steps'],
            'decimals' => (int) ($this->units->row($tenantId, $steps['unit_id'])['decimals'] ?? 0),
            'unit_id' => $steps['unit_id'],
        ];
    }

    /**
     * A bill line's account: the one given (an expense, or an asset such as
     * equipment or stock), else Other costs. Matching bills to stock
     * receipts arrives with stock in the books (A4).
     */
    private function costAccount(int $tenantId, mixed $id, mixed $code, string $field): int
    {
        if ($id !== null || ($code !== null && $code !== '')) {
            $row = is_int($id)
                ? $this->db->one('SELECT id, type, role, is_group, archived_at FROM acc_accounts WHERE tenant_id = :tenant_id AND id = :id', [':tenant_id' => $tenantId, ':id' => $id])
                : (is_string($code) ? $this->db->one('SELECT id, type, role, is_group, archived_at FROM acc_accounts WHERE tenant_id = :tenant_id AND code = :code', [':tenant_id' => $tenantId, ':code' => $code]) : null);
            if ($row === null || $row['archived_at'] !== null || DbBool::of($row['is_group'])) {
                throw AccountingRejectedException::field($field, $field . '.account is not an account here that takes lines');
            }
            // Goods received, not yet billed is allowed: a bill for goods already
            // received clears what the receipt put there (A4a; matching is A4b).
            $received = $row['role'] === 'goods_received_not_billed';
            if (!$received && (!in_array($row['type'], ['expense', 'asset'], true) || in_array($row['role'], ['receivable', 'bank', 'cash', 'sales_tax_input', 'cheques_to_collect', 'provider_clearing'], true))) {
                throw AccountingRejectedException::field($field, $field . '.account must be a cost or an asset bought, such as equipment');
            }

            return (int) $row['id'];
        }

        return AccountingDb::int($this->db->one(
            'SELECT id FROM acc_accounts WHERE tenant_id = :tenant_id AND code = \'6900\' AND type = \'expense\' AND is_group = FALSE AND archived_at IS NULL',
            [':tenant_id' => $tenantId]
        )['id'] ?? null) ?? AccountingDb::int($this->db->one(
            'SELECT id FROM acc_accounts WHERE tenant_id = :tenant_id AND type = \'expense\' AND is_group = FALSE AND archived_at IS NULL AND role IS NULL
              ORDER BY sort_order, code LIMIT 1',
            [':tenant_id' => $tenantId]
        )['id'] ?? null) ?? throw AccountingRejectedException::field($field, 'The chart has no expense account to put this on; add one first');
    }

    /** A supplier's own number for a bill, as printed on their paper. */
    private static function supplierNumber(mixed $value): ?string
    {
        if ($value === null || $value === '') {
            return null;
        }
        if (!is_string($value) || mb_strlen(trim($value)) > 80) {
            throw AccountingRejectedException::field('supplier_number', 'supplier_number is the supplier\'s invoice number, up to 80 characters');
        }

        return trim($value);
    }

    /**
     * The line's income account: the one given, else Services for a service
     * item and Sales for the rest, else the first income account.
     */
    private function incomeAccount(int $tenantId, mixed $id, mixed $code, bool $service, string $field): int
    {
        if ($id !== null || ($code !== null && $code !== '')) {
            $row = is_int($id)
                ? $this->db->one('SELECT id, type, is_group, archived_at FROM acc_accounts WHERE tenant_id = :tenant_id AND id = :id', [':tenant_id' => $tenantId, ':id' => $id])
                : (is_string($code) ? $this->db->one('SELECT id, type, is_group, archived_at FROM acc_accounts WHERE tenant_id = :tenant_id AND code = :code', [':tenant_id' => $tenantId, ':code' => $code]) : null);
            if ($row === null || $row['archived_at'] !== null || DbBool::of($row['is_group'])) {
                throw AccountingRejectedException::field($field, $field . '.account is not an account here that takes lines');
            }
            if ($row['type'] !== 'income') {
                throw AccountingRejectedException::field($field, $field . '.account must be an income account');
            }

            return (int) $row['id'];
        }
        foreach ($service ? ['4200', '4100'] : ['4100', '4200'] as $default) {
            $found = AccountingDb::int($this->db->one(
                'SELECT id FROM acc_accounts WHERE tenant_id = :tenant_id AND code = :code AND type = \'income\' AND is_group = FALSE AND archived_at IS NULL',
                [':tenant_id' => $tenantId, ':code' => $default]
            )['id'] ?? null);
            if ($found !== null) {
                return $found;
            }
        }

        return AccountingDb::int($this->db->one(
            'SELECT id FROM acc_accounts WHERE tenant_id = :tenant_id AND type = \'income\' AND is_group = FALSE AND archived_at IS NULL AND role IS NULL
              ORDER BY sort_order, code LIMIT 1',
            [':tenant_id' => $tenantId]
        )['id'] ?? null) ?? throw AccountingRejectedException::field($field, 'The chart has no income account to put sales on; add one first');
    }

    /**
     * A draft's stored lines, priced again (the date or the tax switch changed).
     *
     * @return list<array<string, mixed>>
     */
    private function linesAgain(int $tenantId, int $id, string $date, bool $inclusive): array
    {
        $out = [];
        foreach ($this->db->all(
            'SELECT l.*, t.rate_bp FROM acc_invoice_lines l JOIN acc_tax_codes t ON t.id = l.tax_code_id AND t.tenant_id = l.tenant_id
              WHERE l.tenant_id = :tenant_id AND l.invoice_id = :id ORDER BY l.line_no',
            [':tenant_id' => $tenantId, ':id' => $id]
        ) as $l) {
            $amount = Taxes::lineAmount((int) $l['quantity'], (int) $l['quantity_decimals'], (int) $l['unit_price_minor'], (int) $l['discount_bp']);
            $split = Taxes::split($amount, (int) $l['rate_bp'], $inclusive);
            $out[] = [
                'variant_id' => AccountingDb::int($l['variant_id']), 'unit_id' => AccountingDb::int($l['unit_id']),
                'description' => (string) $l['description'], 'quantity' => (int) $l['quantity'],
                'quantity_decimals' => (int) $l['quantity_decimals'], 'unit_price_minor' => (int) $l['unit_price_minor'],
                'discount_bp' => (int) $l['discount_bp'], 'tax_code_id' => (int) $l['tax_code_id'],
                'net_minor' => $split['net'], 'tax_minor' => $split['tax'], 'total_minor' => $split['total'],
                'account_id' => (int) $l['account_id'], 'ou_id' => AccountingDb::int($l['ou_id']), 'credits_line_id' => null,
            ];
        }

        return $out;
    }

    /**
     * The lines a credit note takes from its invoice: every line still not
     * credited, or the given `[{line_id, quantity}]`, never more than is left.
     *
     * @return list<array<string, mixed>>
     */
    private function creditLines(int $tenantId, int $invoiceId, mixed $raw, bool $inclusive): array
    {
        $source = [];
        foreach ($this->db->all(
            'SELECT l.*, t.rate_bp,
                    (SELECT COALESCE(SUM(c.quantity), 0) FROM acc_invoice_lines c
                       JOIN acc_invoices ci ON ci.id = c.invoice_id AND ci.tenant_id = c.tenant_id
                      WHERE c.tenant_id = l.tenant_id AND c.credits_line_id = l.id AND ci.status <> \'cancelled\') AS credited
               FROM acc_invoice_lines l JOIN acc_tax_codes t ON t.id = l.tax_code_id AND t.tenant_id = l.tenant_id
              WHERE l.tenant_id = :tenant_id AND l.invoice_id = :id ORDER BY l.line_no',
            [':tenant_id' => $tenantId, ':id' => $invoiceId]
        ) as $l) {
            $source[(int) $l['id']] = $l;
        }
        $wanted = [];
        if ($raw === null) {
            foreach ($source as $lineId => $l) {
                $left = (int) $l['quantity'] - (int) $l['credited'];
                if ($left > 0) {
                    $wanted[$lineId] = $left;
                }
            }
        } else {
            if (!is_array($raw) || !array_is_list($raw) || $raw === []) {
                throw AccountingRejectedException::field('lines', 'lines is a list of {line_id, quantity}, or leave it out to credit everything');
            }
            foreach ($raw as $i => $w) {
                $field = 'lines[' . $i . ']';
                $lineId = is_array($w) ? ($w['line_id'] ?? null) : null;
                if (!is_int($lineId) || !isset($source[$lineId])) {
                    throw AccountingRejectedException::field($field, $field . '.line_id is not a line of this invoice');
                }
                $l = $source[$lineId];
                $given = $w['quantity'] ?? null;
                if ($given === null) {
                    $steps = (int) $l['quantity'] - (int) $l['credited'];
                } else {
                    try {
                        $steps = Quantity::parse(trim((string) (is_scalar($given) ? $given : '')), (int) $l['quantity_decimals'])->steps;
                    } catch (\InvalidArgumentException) {
                        throw AccountingRejectedException::field($field, $field . '.quantity is a number with up to ' . (int) $l['quantity_decimals'] . ' decimals');
                    }
                }
                $wanted[$lineId] = ($wanted[$lineId] ?? 0) + $steps;
            }
        }
        $out = [];
        foreach ($wanted as $lineId => $steps) {
            $l = $source[$lineId];
            $left = (int) $l['quantity'] - (int) $l['credited'];
            if ($steps <= 0 || $steps > $left) {
                throw AccountingRejectedException::field('lines', 'Line ' . (int) $l['line_no'] . ' has ' . $left . ' left to credit (in steps of its quantity)');
            }
            if ($steps === (int) $l['quantity']) {
                $net = (int) $l['net_minor'];
                $tax = (int) $l['tax_minor'];
                $total = (int) $l['total_minor'];
            } else {
                $split = Taxes::split(
                    Taxes::lineAmount($steps, (int) $l['quantity_decimals'], (int) $l['unit_price_minor'], (int) $l['discount_bp']),
                    (int) $l['rate_bp'],
                    $inclusive
                );
                [$net, $tax, $total] = [$split['net'], $split['tax'], $split['total']];
            }
            $out[] = [
                'variant_id' => AccountingDb::int($l['variant_id']), 'unit_id' => AccountingDb::int($l['unit_id']),
                'description' => (string) $l['description'], 'quantity' => $steps, 'quantity_decimals' => (int) $l['quantity_decimals'],
                'unit_price_minor' => (int) $l['unit_price_minor'], 'discount_bp' => (int) $l['discount_bp'],
                'tax_code_id' => (int) $l['tax_code_id'], 'net_minor' => $net, 'tax_minor' => $tax, 'total_minor' => $total,
                'account_id' => (int) $l['account_id'], 'ou_id' => AccountingDb::int($l['ou_id']), 'credits_line_id' => (int) $l['id'],
            ];
        }
        if ($out === []) {
            throw new AccountingRejectedException('Everything on this invoice is already credited', 409);
        }

        return $out;
    }

    /** @param list<array<string, mixed>> $lines */
    private function writeLines(int $tenantId, int $invoiceId, array $lines): void
    {
        $subtotal = 0;
        $tax = 0;
        $discount = 0;
        foreach ($lines as $i => $l) {
            $this->db->run(
                'INSERT INTO acc_invoice_lines (tenant_id, invoice_id, line_no, variant_id, unit_id, description, quantity, quantity_decimals,
                        unit_price_minor, discount_bp, net_minor, tax_code_id, tax_minor, total_minor, account_id, ou_id, credits_line_id)
                 VALUES (:tenant_id, :invoice_id, :line_no, :variant_id, :unit_id, :description, :quantity, :decimals,
                        :price, :discount, :net, :tax_code_id, :tax, :total, :account_id, :ou_id, :credits)',
                [
                    ':tenant_id' => $tenantId, ':invoice_id' => $invoiceId, ':line_no' => $i + 1, ':variant_id' => $l['variant_id'],
                    ':unit_id' => $l['unit_id'], ':description' => $l['description'], ':quantity' => $l['quantity'],
                    ':decimals' => $l['quantity_decimals'], ':price' => $l['unit_price_minor'], ':discount' => $l['discount_bp'],
                    ':net' => $l['net_minor'], ':tax_code_id' => $l['tax_code_id'], ':tax' => $l['tax_minor'], ':total' => $l['total_minor'],
                    ':account_id' => $l['account_id'], ':ou_id' => $l['ou_id'], ':credits' => $l['credits_line_id'],
                ]
            );
            $subtotal += (int) $l['net_minor'];
            $tax += (int) $l['tax_minor'];
            $gross = Taxes::lineAmount((int) $l['quantity'], (int) $l['quantity_decimals'], (int) $l['unit_price_minor'], 0);
            $discount += $gross - Taxes::lineAmount((int) $l['quantity'], (int) $l['quantity_decimals'], (int) $l['unit_price_minor'], (int) $l['discount_bp']);
        }
        $this->db->run(
            'UPDATE acc_invoices SET subtotal_minor = :subtotal, discount_minor = :discount, tax_minor = :tax, total_minor = :total,
                    updated_at = CURRENT_TIMESTAMP
              WHERE tenant_id = :tenant_id AND id = :id',
            [':subtotal' => $subtotal, ':discount' => $discount, ':tax' => $tax, ':total' => $subtotal + $tax, ':tenant_id' => $tenantId, ':id' => $invoiceId]
        );
    }

    /** What issuing needs: an open date, lines, and something to charge. */
    private function ready(int $tenantId, int $id): void
    {
        $row = $this->row($tenantId, $id) ?? throw AccountingRejectedException::notFound('Invoice');
        if ((int) $row['total_minor'] <= 0) {
            throw AccountingRejectedException::field('lines', 'An invoice needs an amount above 0 to be issued');
        }
        $contact = $this->db->one(
            'SELECT archived_at FROM contacts WHERE tenant_id = :tenant_id AND id = :id',
            [':tenant_id' => $tenantId, ':id' => (int) $row['contact_id']]
        );
        if ($contact === null || $contact['archived_at'] !== null) {
            throw AccountingRejectedException::field('contact_id', 'The contact on this document was archived; choose another');
        }
    }

    // ── helpers ──────────────────────────────────────────────────────────

    private function idByExternal(int $tenantId, string $external): ?int
    {
        return AccountingDb::int($this->db->one(
            'SELECT id FROM acc_invoices WHERE tenant_id = :tenant_id AND external_ref = :ref',
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

    private function today(): string
    {
        return ($this->clock !== null ? ($this->clock)() : new \DateTimeImmutable('now', new \DateTimeZone('UTC')))->format('Y-m-d');
    }

    /**
     * @param array<string, mixed> $view
     * @return array<string, mixed>
     */
    private function payload(int $tenantId, array $view, ?int $actor): array
    {
        return [
            'tenant_id' => $tenantId, 'invoice_id' => (int) $view['id'], 'kind' => (string) $view['kind'],
            'number' => $view['number'], 'contact_id' => (int) $view['contact_id'], 'status' => (string) $view['status'],
            'issue_date' => (string) $view['issue_date'], 'due_date' => $view['due_date'], 'currency' => (string) $view['currency'],
            'total_minor' => (int) $view['total_minor'], 'tax_minor' => (int) $view['tax_minor'], 'due_minor' => (int) $view['due_minor'],
            'credits_invoice_id' => $view['credits_invoice_id'], 'acting_profile_id' => $actor,
        ];
    }

    /**
     * Fires an invoice event (and its `.async` twin) for something that
     * happened to it outside this service: a payment settled it.
     *
     * @param array<string, mixed> $extra
     */
    public function announce(string $event, int $tenantId, int $id, ?int $actor, array $extra = []): void
    {
        $this->notify($event, $tenantId, $id, $actor, $extra);
    }

    /** @param array<string, mixed> $extra */
    private function notify(string $event, int $tenantId, int $id, ?int $actor, array $extra = []): void
    {
        $payload = $this->payload($tenantId, $this->get($tenantId, $id), $actor) + $extra;
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
    private function present(array $r): array
    {
        $date = static fn (mixed $v): ?string => is_string($v) && $v !== '' ? substr($v, 0, 10) : null;
        $total = (int) $r['total_minor'];
        $due = in_array($r['status'], self::OPEN, true) ? max(0, $total - (int) $r['paid_minor'] - (int) $r['credited_minor']) : 0;

        return [
            'id' => (int) $r['id'],
            'kind' => (string) $r['kind'],
            'number' => is_string($r['number']) ? $r['number'] : null,
            'supplier_number' => is_string($r['supplier_number'] ?? null) ? $r['supplier_number'] : null,
            'status' => (string) $r['status'],
            'contact_id' => (int) $r['contact_id'],
            'contact_name' => (string) ($r['contact_name'] ?? ''),
            'issue_date' => (string) $date($r['issue_date']),
            'supply_date' => $date($r['supply_date']),
            'due_date' => $date($r['due_date']),
            'payment_terms_days' => (int) $r['payment_terms_days'],
            'overdue' => in_array($r['status'], self::OPEN, true) && $date($r['due_date']) !== null && $date($r['due_date']) < $this->today(),
            'currency' => (string) $r['currency'],
            'prices_include_tax' => DbBool::of($r['prices_include_tax']),
            'subtotal_minor' => (int) $r['subtotal_minor'],
            'discount_minor' => (int) $r['discount_minor'],
            'tax_minor' => (int) $r['tax_minor'],
            'total_minor' => $total,
            'paid_minor' => (int) $r['paid_minor'],
            'credited_minor' => (int) $r['credited_minor'],
            'due_minor' => $due,
            'credits_invoice_id' => AccountingDb::int($r['credits_invoice_id']),
            'entry_id' => AccountingDb::int($r['entry_id']),
            'route_id' => AccountingDb::int($r['route_id']),
            'einvoice_status' => (string) $r['einvoice_status'],
            'einvoice_uuid' => is_string($r['einvoice_uuid']) ? $r['einvoice_uuid'] : null,
            'einvoice_ref' => is_string($r['einvoice_ref']) ? $r['einvoice_ref'] : null,
            'einvoice_qr' => is_string($r['einvoice_qr']) ? $r['einvoice_qr'] : null,
            'einvoice_message' => (string) $r['einvoice_message'],
            'notes' => (string) $r['notes'],
            'external_ref' => is_string($r['external_ref']) ? $r['external_ref'] : null,
            'quote_id' => AccountingDb::int($r['quote_id']),
            'issued_at' => is_string($r['issued_at']) ? $r['issued_at'] : null,
            'issued_by_profile_id' => AccountingDb::int($r['issued_by_profile_id']),
            'created_by_profile_id' => AccountingDb::int($r['created_by_profile_id']),
            'agent_id' => AccountingDb::int($r['agent_id']),
            'on_behalf_of_profile_id' => AccountingDb::int($r['on_behalf_of_profile_id']),
            'owner_plugin' => is_string($r['owner_plugin']) ? $r['owner_plugin'] : null,
            'claimant_profile_id' => AccountingDb::int($r['claimant_profile_id'] ?? null),
            'receipt_stored_file_id' => AccountingDb::int($r['receipt_stored_file_id'] ?? null),
            'created_at' => (string) $r['created_at'],
        ];
    }
}
