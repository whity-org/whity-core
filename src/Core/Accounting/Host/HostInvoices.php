<?php

declare(strict_types=1);

namespace Whity\Core\Accounting\Host;

use Whity\Core\Accounting\Invoices as CoreInvoices;
use Whity\Core\RBAC\CorePermissions;
use Whity\Sdk\Accounting\AccountingRejected;
use Whity\Sdk\Accounting\Invoice;
use Whity\Sdk\Accounting\Invoices;
use Whity\Sdk\PluginInterface;

/**
 * Core's sales invoices for plugins (SDK 1.155, WHIT-1318), in the workspace
 * the call runs in.
 *
 * The instance in the container reads and refuses writes with reason
 * `use_plugin_context`. {@see self::for()} gives a copy bound to a plugin the
 * loader loaded, handed out as `$ctx->accounting()->invoices()`: its drafts
 * belong to that plugin and its external references are namespaced
 * `<slug>:<ref>`, so one plugin never collides with another's.
 */
final class HostInvoices implements Invoices
{
    private const KEYS = [
        'contactId' => 'contact_id', 'issueDate' => 'issue_date', 'supplyDate' => 'supply_date', 'dueDate' => 'due_date',
        'paymentTermsDays' => 'payment_terms_days', 'pricesIncludeTax' => 'prices_include_tax', 'notes' => 'notes',
    ];

    private const LINE_KEYS = [
        'variantId' => 'variant_id', 'description' => 'description', 'quantity' => 'quantity', 'unit' => 'unit',
        'unitPriceMinor' => 'unit_price_minor', 'discountBp' => 'discount_bp', 'taxCode' => 'tax_code',
        'taxCategory' => 'tax_category', 'accountId' => 'account_id', 'ouId' => 'ou_id',
    ];

    public function __construct(
        private readonly CoreInvoices $invoices,
        private readonly HostAccountingGuard $guard,
        private readonly ?string $owner = null,
    ) {
    }

    /** A copy that writes as `$plugin`. Host-side only. */
    public function for(PluginInterface $plugin): self
    {
        return new self($this->invoices, $this->guard, HostAccountingGuard::slugOf($plugin));
    }

    public function list(array $filter = [], int $limit = 50, int $offset = 0): array
    {
        $tenantId = $this->guard->workspace();
        $rows = HostAccountingGuard::run(fn (): array => $this->invoices->list($tenantId, array_filter([
            'kind' => $filter['kind'] ?? null,
            'status' => $filter['status'] ?? null,
            'contact_id' => $filter['contactId'] ?? null,
            'from' => $filter['from'] ?? null,
            'to' => $filter['to'] ?? null,
        ], static fn (mixed $v): bool => $v !== null), $limit, $offset));

        return array_map(fn (array $row): Invoice => $this->get((int) $row['id']), $rows);
    }

    public function get(int $invoiceId): Invoice
    {
        $tenantId = $this->guard->workspace();

        return Invoice::fromArray(HostAccountingGuard::run(fn (): array => $this->invoices->get($tenantId, $invoiceId)));
    }

    public function createDraft(array $data, int $actingProfileId, ?string $externalRef = null): Invoice
    {
        $owner = $this->owner();
        $tenantId = $this->guard->workspace();
        $this->guard->writer($tenantId, $actingProfileId, CorePermissions::INVOICES_WRITE);
        $body = [];
        foreach (self::KEYS as $from => $to) {
            if (array_key_exists($from, $data)) {
                $body[$to] = $data[$from];
            }
        }
        if (isset($data['lines']) && is_array($data['lines'])) {
            $body['lines'] = array_map(static function (mixed $line): mixed {
                if (!is_array($line)) {
                    return $line;
                }
                $out = [];
                foreach (self::LINE_KEYS as $from => $to) {
                    if (array_key_exists($from, $line)) {
                        $out[$to] = $line[$from];
                    }
                }

                return $out;
            }, array_values($data['lines']));
        }
        $result = HostAccountingGuard::run(fn (): array => $this->invoices->create($tenantId, $actingProfileId, $body, [
            'owner_plugin' => $owner,
            'external_ref' => $externalRef === null ? null : $owner . ':' . $externalRef,
        ]));

        return Invoice::fromArray($result['invoice']);
    }

    public function issue(int $invoiceId, int $actingProfileId): Invoice
    {
        $this->owner();
        $tenantId = $this->guard->workspace();
        $this->guard->writer($tenantId, $actingProfileId, CorePermissions::INVOICES_WRITE);

        return Invoice::fromArray(HostAccountingGuard::run(fn (): array => $this->invoices->issue($tenantId, $invoiceId, $actingProfileId)));
    }

    public function credit(int $invoiceId, int $actingProfileId, ?array $lines = null, string $reason = '', ?string $externalRef = null): Invoice
    {
        $owner = $this->owner();
        $tenantId = $this->guard->workspace();
        $this->guard->writer($tenantId, $actingProfileId, CorePermissions::INVOICES_WRITE);
        $data = ['reason' => $reason];
        if ($lines !== null) {
            $data['lines'] = array_map(static fn (array $l): array => array_filter(
                ['line_id' => $l['lineId'], 'quantity' => $l['quantity'] ?? null],
                static fn (mixed $v): bool => $v !== null
            ), $lines);
        }
        $result = HostAccountingGuard::run(fn (): array => $this->invoices->credit($tenantId, $invoiceId, $actingProfileId, $data, [
            'owner_plugin' => $owner,
            'external_ref' => $externalRef === null ? null : $owner . ':' . $externalRef,
        ]));

        return Invoice::fromArray($result['invoice']);
    }

    public function cancelDraft(int $invoiceId, int $actingProfileId): Invoice
    {
        $this->owner();
        $tenantId = $this->guard->workspace();
        $this->guard->writer($tenantId, $actingProfileId, CorePermissions::INVOICES_WRITE);

        return Invoice::fromArray(HostAccountingGuard::run(fn (): array => $this->invoices->cancel($tenantId, $invoiceId, $actingProfileId)));
    }

    private function owner(): string
    {
        return $this->owner ?? throw HostAccountingGuard::useContext('$ctx->accounting()->invoices()');
    }
}
