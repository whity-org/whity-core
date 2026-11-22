<?php

declare(strict_types=1);

namespace Whity\Sdk\Contribute;

use Whity\Sdk\Calendar\CommissionClose;

/**
 * Books a closed commission period as an expense (SDK 1.141, WHIT-1334,
 * design section 35 §4.3, register SL.6).
 *
 * Core's staff commission is worked out from the calendar; when an owner
 * closes a period, core freezes each member of staff's totals and hands the
 * close here. Accounting (A3) implements it: an expense to Commission and a
 * payable to each person, in each currency. A payroll plugin could too.
 *
 * Core binds at most one, through the host's service container, keyed by this
 * interface. With none bound the close still stands, and the report says its
 * totals are ready for the books.
 *
 * {@see record()} runs after the close is saved. Answer a reference people can
 * read (the journal entry's number, "JE-0412"), which core keeps on the close
 * so it is booked once. Throw to say it could not be booked now: the close
 * stays, without a reference, and can be sent again.
 */
interface WorkspaceCommissionExpenses
{
    /** Book the close; answer its reference. */
    public function record(CommissionClose $close): string;
}
