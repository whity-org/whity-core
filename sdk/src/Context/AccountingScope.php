<?php

declare(strict_types=1);

namespace Whity\Sdk\Context;

use Whity\Sdk\Accounting\Accounts;
use Whity\Sdk\Accounting\Invoices;
use Whity\Sdk\Accounting\Ledger;
use Whity\Sdk\Accounting\Payments;
use Whity\Sdk\Accounting\Periods;
use Whity\Sdk\Accounting\Reports;
use Whity\Sdk\Accounting\Taxes;

/**
 * Core's books for one plugin, in the workspace the call runs in (SDK 1.154,
 * WHIT-1318): `$ctx->accounting()`.
 *
 * `ledger()` and `accounts()` are bound to the plugin the loader loaded: the
 * entries posted are stored under that plugin's name (`hr.payroll_run`) and
 * the accounts added belong to it, never to a name the plugin passes. They are
 * the only way a plugin writes the books; the services in the container read,
 * and refuse a write with reason `use_plugin_context`.
 *
 * Each accessor throws {@see ServiceUnavailable} on a host without accounting.
 */
interface AccountingScope
{
    public function accounts(): Accounts;

    public function ledger(): Ledger;

    public function periods(): Periods;

    public function reports(): Reports;

    /** Sales invoices and credit notes; drafts and credits are your plugin's (SDK 1.155). */
    public function invoices(): Invoices;

    /** Tax codes and the arithmetic of a line, read-only (SDK 1.155). */
    public function taxes(): Taxes;

    /** Payments received, allocations and transfers; your plugin's payments are its own (SDK 1.156). */
    public function payments(): Payments;
}
