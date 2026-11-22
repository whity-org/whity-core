<?php

declare(strict_types=1);

namespace Whity\Sdk\Contribute;

use Whity\Sdk\Accounting\EInvoiceResult;
use Whity\Sdk\Accounting\Invoice;

/**
 * Reports issued invoices and credit notes to a tax authority (SDK 1.155,
 * WHIT-1318, design section 32 §7.4, register AC.22). JoFotara, Jordan's
 * national invoicing system, is the first, as a paid integration plugin.
 *
 * Core binds at most one, through the host's service container, keyed by this
 * interface. Core keeps what e-invoicing needs anyway: numbers with no gaps,
 * the invoice frozen once issued, and the e-invoice columns on it.
 *
 * The workspace comes from the call, never from an argument. {@see isOn()}
 * says whether the workspace the call runs in reports to this authority (it is
 * registered and the connection is set up); when it answers no, core marks
 * the invoice `not_required` and does not call {@see submit()}.
 *
 * {@see submit()} runs after the invoice is issued and committed, so a slow or
 * failing authority never blocks issuing. Answer the authority's result;
 * throw to say it could not be sent now, and the invoice stays `pending`.
 */
interface WorkspaceEInvoicing
{
    /** The authority's country, ISO 3166 alpha-2: `JO`. */
    public function country(): string;

    /** Whether the workspace the call runs in reports its invoices here. */
    public function isOn(): bool;

    /** Report one issued invoice or credit note. */
    public function submit(Invoice $invoice): EInvoiceResult;
}
