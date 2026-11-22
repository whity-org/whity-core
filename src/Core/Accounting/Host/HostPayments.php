<?php

declare(strict_types=1);

namespace Whity\Core\Accounting\Host;

use Whity\Core\Accounting\Payments as CorePayments;
use Whity\Core\RBAC\CorePermissions;
use Whity\Sdk\Accounting\Payment;
use Whity\Sdk\Accounting\PaymentRequest;
use Whity\Sdk\Accounting\Payments;
use Whity\Sdk\PluginInterface;

/**
 * Core's payments for plugins (SDK 1.156, WHIT-1318), in the workspace the
 * call runs in. Whity Pay records its payments and payouts here.
 *
 * The instance in the container reads and refuses writes with reason
 * `use_plugin_context`. {@see self::for()} gives a copy bound to a plugin the
 * loader loaded, handed out as `$ctx->accounting()->payments()`: its payments
 * belong to that plugin and its external references are namespaced
 * `<slug>:<ref>`, so Whity Pay's payment id records once.
 */
final class HostPayments implements Payments
{
    public function __construct(
        private readonly CorePayments $payments,
        private readonly HostAccountingGuard $guard,
        private readonly ?string $owner = null,
    ) {
    }

    /** A copy that writes as `$plugin`. Host-side only. */
    public function for(PluginInterface $plugin): self
    {
        return new self($this->payments, $this->guard, HostAccountingGuard::slugOf($plugin));
    }

    public function recordReceived(PaymentRequest $request): Payment
    {
        $owner = $this->owner();
        $tenantId = $this->guard->workspace();
        $this->guard->writer($tenantId, $request->actingProfileId, CorePermissions::PAYMENTS_WRITE);
        $data = array_filter([
            'contact_id' => $request->contactId,
            'amount_minor' => $request->amountMinor,
            'method' => $request->method,
            'paid_on' => $request->paidOn,
            'invoice_id' => $request->invoiceId,
            'fee_minor' => $request->feeMinor,
            'reference' => $request->reference,
            'cheque_number' => $request->chequeNumber,
            'cheque_bank' => $request->chequeBank,
            'cheque_due_on' => $request->chequeDueOn,
        ], static fn (mixed $v): bool => $v !== null);
        $result = HostAccountingGuard::run(fn (): array => $this->payments->receive($tenantId, $request->actingProfileId, $data, [
            'owner_plugin' => $owner,
            'external_ref' => $request->externalRef === null ? null : $owner . ':' . $request->externalRef,
        ]));

        return Payment::fromArray($result['payment']);
    }

    public function allocate(int $paymentId, int $invoiceId, int $actingProfileId, ?int $amountMinor = null): Payment
    {
        $this->owner();
        $tenantId = $this->guard->workspace();
        $this->guard->writer($tenantId, $actingProfileId, CorePermissions::PAYMENTS_WRITE);

        return Payment::fromArray(HostAccountingGuard::run(fn (): array => $this->payments->allocate($tenantId, $paymentId, $invoiceId, $amountMinor, $actingProfileId)));
    }

    public function recordTransfer(int $fromAccountId, int $toAccountId, int $amountMinor, int $actingProfileId, ?string $paidOn = null, string $reference = '', ?string $externalRef = null): Payment
    {
        $owner = $this->owner();
        $tenantId = $this->guard->workspace();
        $this->guard->writer($tenantId, $actingProfileId, CorePermissions::PAYMENTS_WRITE);
        $result = HostAccountingGuard::run(fn (): array => $this->payments->transfer($tenantId, $actingProfileId, array_filter([
            'from_account_id' => $fromAccountId,
            'to_account_id' => $toAccountId,
            'amount_minor' => $amountMinor,
            'paid_on' => $paidOn,
            'reference' => $reference,
        ], static fn (mixed $v): bool => $v !== null), [
            'owner_plugin' => $owner,
            'external_ref' => $externalRef === null ? null : $owner . ':' . $externalRef,
        ]));

        return Payment::fromArray($result['payment']);
    }

    public function get(int $paymentId): Payment
    {
        $tenantId = $this->guard->workspace();

        return Payment::fromArray(HostAccountingGuard::run(fn (): array => $this->payments->get($tenantId, $paymentId)));
    }

    public function list(array $filter = [], int $limit = 50, int $offset = 0): array
    {
        $tenantId = $this->guard->workspace();
        $rows = HostAccountingGuard::run(fn (): array => $this->payments->list($tenantId, array_filter([
            'direction' => $filter['direction'] ?? null,
            'contact_id' => $filter['contactId'] ?? null,
            'method' => $filter['method'] ?? null,
            'cheque_status' => $filter['chequeStatus'] ?? null,
            'from' => $filter['from'] ?? null,
            'to' => $filter['to'] ?? null,
        ], static fn (mixed $v): bool => $v !== null), $limit, $offset));

        return array_map(static fn (array $row): Payment => Payment::fromArray($row), $rows);
    }

    private function owner(): string
    {
        return $this->owner ?? throw HostAccountingGuard::useContext('$ctx->accounting()->payments()');
    }
}
