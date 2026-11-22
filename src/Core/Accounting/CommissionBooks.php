<?php

declare(strict_types=1);

namespace Whity\Core\Accounting;

use Whity\Sdk\Calendar\CommissionClose;
use Whity\Sdk\Contribute\WorkspaceCommissionExpenses;

/**
 * Core accounting books a closed commission period (WHIT-1318 A3a, design
 * section 35 §4.3, register SL.6): when an owner closes a period by hand, one
 * entry in the general journal debits Staff commission and credits
 * Commission to pay, a line per member of staff, named. Its number is the
 * close's reference, so a close is booked once.
 *
 * Until multi-currency (A7) only commission in the books' currency is booked;
 * a close with another currency is refused and stays ready for the books.
 */
final class CommissionBooks implements WorkspaceCommissionExpenses
{
    public function __construct(
        private readonly AccountingDb $db,
        private readonly AccountingWorkspace $workspace,
        private readonly Ledger $ledger,
    ) {
    }

    public function record(CommissionClose $close): string
    {
        $tenantId = $close->workspaceId;
        $currency = $this->workspace->books($tenantId)['base_currency'];
        $expense = $this->workspace->roleAccountId($tenantId, 'commission_expense');
        $payable = $this->workspace->roleAccountId($tenantId, 'commission_payable');
        $lines = [];
        $total = 0;
        foreach ($close->lines as $line) {
            if ($line->commissionMinor <= 0) {
                continue;
            }
            if (strtoupper($line->currency) !== $currency) {
                throw new AccountingRejectedException('Commission in ' . $line->currency . ' waits for the books in other currencies; the books are in ' . $currency, 409);
            }
            $name = $this->personName($line->profileId);
            $lines[] = ['account_id' => $payable, 'credit_minor' => $line->commissionMinor, 'description' => $name, 'source_line_type' => 'profile', 'source_line_id' => $line->profileId];
            $total += $line->commissionMinor;
        }
        if ($total === 0) {
            throw new AccountingRejectedException('There is no commission to book in this period', 409);
        }
        array_unshift($lines, ['account_id' => $expense, 'debit_minor' => $total, 'description' => 'Commission ' . $close->from . ' to ' . $close->to]);
        $posted = $this->ledger->post($tenantId, $close->closedByProfileId, [
            'journal' => 'GEN',
            'date' => $close->to,
            'memo' => 'Staff commission, ' . $close->from . ' to ' . $close->to,
            'source_type' => 'commission',
            'source_id' => $close->id,
            'idempotency_key' => 'commission_close:' . $close->id,
            'lines' => $lines,
        ]);

        return (string) $posted['entry']['number'];
    }

    private function personName(int $profileId): string
    {
        $row = $this->db->one('SELECT display_name FROM profiles WHERE id = :id', [':id' => $profileId]);

        return is_string($row['display_name'] ?? null) && $row['display_name'] !== '' ? $row['display_name'] : 'A member of staff';
    }
}
