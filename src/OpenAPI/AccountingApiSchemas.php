<?php

declare(strict_types=1);

namespace Whity\OpenAPI;

/**
 * Route and component schemas for accounting, phase A1 (WHIT-1318, design
 * section 32). Kept apart from {@see CoreApiSchemas}, which merges these in.
 *
 * MCP: every route is a tool in the `core.accounting` toolset. No write
 * declares an undo, so an agent's change is always a proposal its person
 * approves (register AC.25).
 */
final class AccountingApiSchemas
{
    /** @return list<array{method: string, path: string, requiredRole: ?string, requiredPermission: ?string, schema: array<string, mixed>}> */
    public static function routes(): array
    {
        $tags = ['accounting'];
        $account = self::data(SchemaBuilder::ref('AccountingAccount'));
        $entry = self::data(SchemaBuilder::ref('AccountingEntry'));
        $manual = self::data(SchemaBuilder::ref('AccountingManualEntry'));
        $period = self::data(SchemaBuilder::ref('AccountingPeriod'));
        $notFound = [404 => self::error('Not found')];
        $refused = [409 => self::error('Refused: a duplicate code, a closed month, or already done'), 422 => self::error('A value that doesn\'t look right')];
        $range = [
            self::param('from', 'string', 'First day, YYYY-MM-DD. Default: the first of this month.'),
            self::param('to', 'string', 'Last day, YYYY-MM-DD. Default: today.'),
        ];

        return [
            self::route('GET', '/api/accounting/books', 'accounting:read', [
                'summary' => 'Show the workspace\'s books: base currency, tax pack and lock date',
                'description' => 'The books are set up the first time accounting is opened, with the Jordan chart of accounts. The base currency is fixed at the first posting.',
                'tags' => $tags,
                'responses' => [200 => self::json('The books', self::data(SchemaBuilder::ref('AccountingBooks')))] + self::auth(),
            ]),
            self::route('GET', '/api/accounting/accounts', 'accounting:read', [
                'summary' => 'List the chart of accounts',
                'description' => 'In tree order, each with its depth and its balance to date (debits minus credits, minor units). Headings carry their children\'s balances.',
                'tags' => $tags,
                'parameters' => [self::param('archived', 'string', '`true` to include archived accounts.')],
                'responses' => [200 => self::json('Accounts', self::object([
                    'data' => ['type' => 'array', 'items' => SchemaBuilder::ref('AccountingAccount')],
                    'currency' => self::str(),
                ], ['data', 'currency']))] + self::auth(),
            ]),
            self::route('POST', '/api/accounting/accounts', 'accounting:settings', [
                'summary' => 'Add an account to the chart',
                'description' => 'Under a heading (its type is taken), or at the top with a `type`.',
                'tags' => $tags,
                'request' => 'AccountingAccountRequest',
                'responses' => [201 => self::json('The account', $account)] + $refused + self::auth(),
            ]),
            self::route('GET', '/api/accounting/accounts/{id:\d+}', 'accounting:read', [
                'summary' => 'Show one account',
                'tags' => $tags,
                'responses' => [200 => self::json('The account', $account)] + $notFound + self::auth(),
            ]),
            self::route('PATCH', '/api/accounting/accounts/{id:\d+}', 'accounting:settings', [
                'summary' => 'Rename, recode or move an account',
                'description' => 'The code can change only until the account has entries. It stays under a heading of its own type.',
                'tags' => $tags,
                'request' => 'AccountingAccountChanges',
                'responses' => [200 => self::json('The account', $account)] + $notFound + $refused + self::auth(),
            ]),
            self::route('POST', '/api/accounting/accounts/{id:\d+}/archive', 'accounting:settings', [
                'summary' => 'Archive an account',
                'description' => 'Refused for an account the books use (receivable, sales tax...), one with a balance, or a heading with open accounts under it.',
                'tags' => $tags,
                'responses' => [200 => self::json('The account', $account)] + $notFound + $refused + self::auth(),
            ]),
            self::route('POST', '/api/accounting/accounts/{id:\d+}/restore', 'accounting:settings', [
                'summary' => 'Bring an archived account back',
                'tags' => $tags,
                'responses' => [200 => self::json('The account', $account)] + $notFound + self::auth(),
            ]),
            self::route('GET', '/api/accounting/accounts/{id:\d+}/statement', 'accounting:read', [
                'summary' => 'An account\'s lines with a running balance',
                'description' => 'Oldest first, with the opening and closing balance. For income and costs the opening counts only this fiscal year. Page on with `after_date` and `after_line` from `next`.',
                'tags' => $tags,
                'parameters' => [...$range,
                    self::param('after_date', 'string', 'The date of the last line of the page before (from `next`).'),
                    self::param('after_line', 'integer', 'The line id of the last line of the page before (from `next`).'),
                    self::param('limit', 'integer', 'Lines per page, at most 500. Default 200.'),
                ],
                'responses' => [200 => self::json('The statement', self::data(SchemaBuilder::ref('AccountingStatement')))] + $notFound + self::auth(),
            ]),
            self::route('GET', '/api/accounting/journals', 'accounting:read', [
                'summary' => 'List the journals',
                'description' => 'Sales, purchases, bank, cash, general, stock and opening balances, and any a plugin added.',
                'tags' => $tags,
                'responses' => [200 => self::json('Journals', self::list('AccountingJournal'))] + self::auth(),
            ]),
            self::route('GET', '/api/accounting/entries', 'accounting:read', [
                'summary' => 'List posted journal entries',
                'description' => 'Newest first, without their lines. Page back with `before_id`.',
                'tags' => $tags,
                'parameters' => [
                    self::param('journal_id', 'integer', 'Only entries in this journal.'),
                    self::param('account_id', 'integer', 'Only entries with a line on this account.'),
                    self::param('source_type', 'string', 'Only entries from this source, such as `manual` or `pos.sale`.'),
                    self::param('source_id', 'integer', 'Only entries from this source record.'),
                    self::param('from', 'string', 'Dated on or after, YYYY-MM-DD.'),
                    self::param('to', 'string', 'Dated on or before, YYYY-MM-DD.'),
                    self::param('before_id', 'integer', 'Entries older than this one.'),
                    self::param('limit', 'integer', 'Entries per page, at most 200. Default 50.'),
                ],
                'responses' => [200 => self::json('Entries', self::list('AccountingEntry'))] + self::auth(),
            ]),
            self::route('GET', '/api/accounting/entries/{id:\d+}', 'accounting:read', [
                'summary' => 'Show one posted entry with its lines',
                'tags' => $tags,
                'responses' => [200 => self::json('The entry', $entry)] + $notFound + self::auth(),
            ]),
            self::route('POST', '/api/accounting/entries/{id:\d+}/reverse', 'accounting:post', [
                'summary' => 'Reverse a posted entry',
                'description' => 'Posts its mirror, every debit a credit, linked to it. An entry is reversed once. Dated `date` when given, else the original date while its month is open, else today.',
                'tags' => $tags,
                'request' => 'AccountingReverseRequest',
                'responses' => [201 => self::json('The reversing entry', $entry)] + $notFound + $refused + self::auth(),
            ]),
            self::route('GET', '/api/accounting/manual-entries', 'accounting:read', [
                'summary' => 'List journal entries people wrote',
                'description' => 'Drafts, posted and cancelled, newest first.',
                'tags' => $tags,
                'parameters' => [
                    self::param('status', 'string', '`draft`, `posted` or `cancelled`.'),
                    self::param('before_id', 'integer', 'Entries older than this one.'),
                    self::param('limit', 'integer', 'Entries per page, at most 200. Default 50.'),
                ],
                'responses' => [200 => self::json('Manual entries', self::list('AccountingManualEntry'))] + self::auth(),
            ]),
            self::route('POST', '/api/accounting/manual-entries', 'accounting:post', [
                'summary' => 'Draft a journal entry',
                'description' => 'Amounts in the base currency\'s normal units ("12.500"). A draft is not in the books until it is posted. Accounts kept by documents (receivables, payables, stock, tax) take lines only in the opening journal.',
                'tags' => $tags,
                'request' => 'AccountingManualEntryRequest',
                'responses' => [201 => self::json('The draft', $manual)] + $refused + self::auth(),
            ]),
            self::route('GET', '/api/accounting/manual-entries/{id:\d+}', 'accounting:read', [
                'summary' => 'Show one manual entry with its lines',
                'tags' => $tags,
                'responses' => [200 => self::json('The entry', $manual)] + $notFound + self::auth(),
            ]),
            self::route('PATCH', '/api/accounting/manual-entries/{id:\d+}', 'accounting:post', [
                'summary' => 'Change a draft journal entry',
                'description' => 'Sending `lines` replaces them all.',
                'tags' => $tags,
                'request' => 'AccountingManualEntryRequest',
                'responses' => [200 => self::json('The draft', $manual)] + $notFound + $refused + self::auth(),
            ]),
            self::route('POST', '/api/accounting/manual-entries/{id:\d+}/post', 'accounting:post', [
                'summary' => 'Post a draft journal entry to the books',
                'description' => 'Refused when debits and credits differ, or the date is in a closed month.',
                'tags' => $tags,
                'responses' => [200 => self::json('The entry, posted', $manual)] + $notFound + $refused + self::auth(),
            ]),
            self::route('POST', '/api/accounting/manual-entries/{id:\d+}/cancel', 'accounting:post', [
                'summary' => 'Cancel a draft journal entry',
                'tags' => $tags,
                'responses' => [200 => self::json('The entry, cancelled', $manual)] + $notFound + $refused + self::auth(),
            ]),
            self::route('GET', '/api/accounting/periods', 'accounting:read', [
                'summary' => 'List the months of the books',
                'description' => 'Newest first: open, closed or locked.',
                'tags' => $tags,
                'parameters' => [self::param('year', 'integer', 'Only the months of this calendar year.')],
                'responses' => [200 => self::json('Months', self::list('AccountingPeriod'))] + self::auth(),
            ]),
            self::route('POST', '/api/accounting/periods/{id:\d+}/close', 'accounting:close', [
                'summary' => 'Close a month',
                'description' => 'Nothing more can be posted with a date in it until it is reopened.',
                'tags' => $tags,
                'responses' => [200 => self::json('The month', $period)] + $notFound + $refused + self::auth(),
            ]),
            self::route('POST', '/api/accounting/periods/{id:\d+}/reopen', 'accounting:close', [
                'summary' => 'Reopen a closed month, saying why',
                'tags' => $tags,
                'request' => 'AccountingReopenRequest',
                'responses' => [200 => self::json('The month', $period)] + $notFound + $refused + self::auth(),
            ]),
            self::route('GET', '/api/accounting/reports/trial-balance', 'accounting:read', [
                'summary' => 'The trial balance',
                'description' => 'Every account with an opening balance or lines: opening, debits and credits in the range, closing, in minor units. `balanced` is true when debits equal credits.',
                'tags' => $tags,
                'parameters' => [...$range, self::param('ou_id', 'integer', 'Only lines of this branch (organisational unit).')],
                'responses' => [200 => self::json('The trial balance', self::data(SchemaBuilder::ref('AccountingTrialBalance')))] + self::auth(),
            ]),
            ...self::invoiceRoutes($notFound, $refused),
            ...self::paymentRoutes($notFound, $refused),
            ...self::billRoutes($notFound, $refused),
            ...self::expenseRoutes($notFound, $refused),
            ...self::stockRoutes($notFound, $refused),
        ];
    }

    /**
     * Phase A4a: stock in the books.
     *
     * @param array<int, array<string, mixed>> $notFound
     * @param array<int, array<string, mixed>> $refused
     * @return list<array{method: string, path: string, requiredRole: ?string, requiredPermission: ?string, schema: array<string, mixed>}>
     */
    private static function stockRoutes(array $notFound, array $refused): array
    {
        $tags = ['accounting'];
        $status = self::data(SchemaBuilder::ref('AccountingStockStatus'));

        return [
            self::route('GET', '/api/accounting/stock', 'accounting:read', [
                'summary' => 'Show stock in the books',
                'description' => 'Whether stock posts to the books, since when, what the stock is worth at its moving average cost and what the Stock account says. Minor units.',
                'tags' => $tags,
                'responses' => [200 => self::json('Stock in the books', $status)] + self::auth(),
            ]),
            self::route('PATCH', '/api/accounting/stock', 'accounting:settings', [
                'summary' => 'Turn stock in the books on or off',
                'description' => 'On by default. Off stops posting stock; on again brings the Stock account to the stock\'s value with the next stock change, or at once with `/stock/opening`.',
                'tags' => $tags,
                'request' => 'AccountingStockSwitch',
                'responses' => [200 => self::json('Stock in the books', $status)] + $refused + self::auth(),
            ]),
            self::route('POST', '/api/accounting/stock/opening', 'accounting:settings', [
                'summary' => 'Bring stock into the books now',
                'description' => 'Values all stock at its current cost and posts what the Stock account lacks against Opening balances, once. Otherwise it happens with the first stock change.',
                'tags' => $tags,
                'request' => 'AccountingStockOpening',
                'responses' => [201 => self::json('What was posted', self::data(self::object([
                    'posted' => self::bool(),
                    'entry_id' => self::int(true),
                    'amount_minor' => self::int() + ['description' => 'What the Stock account was brought up (or down) by.'],
                    'unknown' => ['type' => 'array', 'items' => self::int(), 'description' => 'Variants with no cost yet, not valued.'],
                ], ['posted', 'amount_minor', 'unknown'])))] + $refused + self::auth(),
            ]),
            self::route('GET', '/api/accounting/stock/waiting', 'accounting:read', [
                'summary' => 'Stock changes waiting for a cost',
                'description' => 'Moves since stock went into the books whose cost is unknown, so they posted nothing.',
                'tags' => $tags,
                'responses' => [200 => self::json('Waiting', ['type' => 'object', 'properties' => ['data' => ['type' => 'array', 'items' => self::object([
                    'move_id' => self::int(),
                    'variant_id' => self::int(),
                    'item_name' => self::str(),
                    'quantity' => self::int(),
                    'source_type' => self::str(),
                    'source_id' => self::int(true),
                    'occurred_at' => self::str(),
                ], ['move_id', 'variant_id', 'item_name', 'quantity'])]], 'required' => ['data']])] + self::auth(),
            ]),
        ];
    }

    /**
     * Phase A3b: expense claims and petty cash.
     *
     * @param array<int, array<string, mixed>> $notFound
     * @param array<int, array<string, mixed>> $refused
     * @return list<array{method: string, path: string, requiredRole: ?string, requiredPermission: ?string, schema: array<string, mixed>}>
     */
    private static function expenseRoutes(array $notFound, array $refused): array
    {
        $tags = ['accounting'];
        $claim = self::data(SchemaBuilder::ref('AccountingInvoice'));

        return [
            self::route('GET', '/api/accounting/expense-claims', 'expenses:submit', [
                'summary' => 'List expense claims',
                'description' => 'Your own claims, newest first. With `all=true`, people who can read bills see everyone\'s.',
                'tags' => $tags,
                'parameters' => [
                    self::param('all', 'string', '`true` for everyone\'s claims (needs bills:read).'),
                    self::param('limit', 'integer', 'At most 200. Default 50.'),
                    self::param('offset', 'integer', 'Skip this many.'),
                ],
                'responses' => [200 => self::json('Claims', self::list('AccountingInvoice'))] + self::auth(),
            ]),
            self::route('POST', '/api/accounting/expense-claims', 'expenses:submit', [
                'summary' => 'Claim back money you spent',
                'description' => 'Each line is what you paid, tax included. The claim is a bill from you: it posts the costs and the tax to reclaim against what the workspace owes you, after the bill route when the workspace has one, and is paid back as a payment made.',
                'tags' => $tags,
                'request' => 'AccountingExpenseClaimRequest',
                'responses' => [201 => self::json('The claim', $claim)] + $refused + self::auth(),
            ]),
            self::route('GET', '/api/accounting/expense-claims/{id:\d+}', 'expenses:submit', [
                'summary' => 'Show an expense claim',
                'description' => 'Your own, or anyone\'s when you can read bills.',
                'tags' => $tags,
                'responses' => [200 => self::json('The claim', $claim)] + $notFound + self::auth(),
            ]),
            self::route('POST', '/api/accounting/expense-claims/{id:\d+}/reimburse', 'payments:write', [
                'summary' => 'Pay back an expense claim',
                'description' => 'Pays the person what the claim is still owed, from the bank by default.',
                'tags' => $tags,
                'request' => 'AccountingReimburseRequest',
                'responses' => [201 => self::json('The payment', self::data(SchemaBuilder::ref('AccountingPayment')))] + $notFound + $refused + self::auth(),
            ]),
            self::route('GET', '/api/accounting/petty-cash', 'payments:read', [
                'summary' => 'Show petty cash',
                'description' => 'The Petty cash account and what is in it. Its history is the account\'s statement; top it up with a transfer from the bank.',
                'tags' => $tags,
                'parameters' => [self::param('as_of', 'string', 'YYYY-MM-DD. Default: today.')],
                'responses' => [200 => self::json('Petty cash', self::data(SchemaBuilder::ref('AccountingPettyCash')))] + self::auth(),
            ]),
            self::route('POST', '/api/accounting/petty-cash/spends', 'payments:write', [
                'summary' => 'Record money spent from petty cash',
                'description' => 'A bill from Cash purchases, posted and paid at once from Petty cash, so its costs and reclaimable tax reach the books. Amounts are what was paid, tax included.',
                'tags' => $tags,
                'request' => 'AccountingExpenseClaimRequest',
                'responses' => [201 => self::json('The bill and its payment', self::data(self::object([
                    'bill' => SchemaBuilder::ref('AccountingInvoice'),
                    'payment' => SchemaBuilder::ref('AccountingPayment'),
                ], ['bill', 'payment'])))] + $refused + self::auth(),
            ]),
        ];
    }

    /**
     * Phase A3a: supplier bills and credits, payments made, aged payables and
     * the supplier statement.
     *
     * @param array<int, array<string, mixed>> $notFound
     * @param array<int, array<string, mixed>> $refused
     * @return list<array{method: string, path: string, requiredRole: ?string, requiredPermission: ?string, schema: array<string, mixed>}>
     */
    private static function billRoutes(array $notFound, array $refused): array
    {
        $tags = ['accounting'];
        $bill = self::data(SchemaBuilder::ref('AccountingInvoice'));

        return [
            self::route('GET', '/api/accounting/bills', 'bills:read', [
                'summary' => 'List supplier bills and supplier credits',
                'description' => 'Newest first. `due_minor` is what is still owed to the supplier.',
                'tags' => $tags,
                'parameters' => [
                    self::param('kind', 'string', '`purchase` or `purchase_credit`. Default: both.'),
                    self::param('status', 'string', '`draft`, `waiting_approval`, `issued` (posted), `partly_paid`, `paid`, `credited`, `cancelled`, or `overdue`.'),
                    self::param('contact_id', 'integer', 'One supplier\'s bills.'),
                    self::param('q', 'string', 'Part of the number or the supplier\'s name.'),
                    self::param('from', 'string', 'Dated on or after, YYYY-MM-DD.'),
                    self::param('to', 'string', 'Dated on or before, YYYY-MM-DD.'),
                    self::param('limit', 'integer', 'At most 200. Default 50.'),
                    self::param('offset', 'integer', 'Skip this many.'),
                ],
                'responses' => [200 => self::json('Bills', self::list('AccountingInvoice'))] + self::auth(),
            ]),
            self::route('POST', '/api/accounting/bills', 'bills:write', [
                'summary' => 'Enter a supplier bill',
                'description' => 'A draft posts nothing. Lines are costs or things bought (an expense or an asset account; default Other costs), each with the tax to reclaim from its tax category. `supplier_number` is the supplier\'s own invoice number. Bills are not matched to stock receipts yet; that comes with stock in the books.',
                'tags' => $tags,
                'request' => 'AccountingBillRequest',
                'responses' => [201 => self::json('The draft', $bill), 200 => self::json('The same draft, already made with this key', $bill)] + $refused + self::auth(),
            ]),
            self::route('GET', '/api/accounting/bills/{id:\d+}', 'bills:read', [
                'summary' => 'Show a supplier bill or credit',
                'tags' => $tags,
                'responses' => [200 => self::json('The bill', $bill)] + $notFound + self::auth(),
            ]),
            self::route('PATCH', '/api/accounting/bills/{id:\d+}', 'bills:write', [
                'summary' => 'Change a draft bill',
                'description' => 'Only a draft changes; `lines`, when given, replace every line.',
                'tags' => $tags,
                'request' => 'AccountingBillChanges',
                'responses' => [200 => self::json('The draft', $bill)] + $notFound + $refused + self::auth(),
            ]),
            self::route('POST', '/api/accounting/bills/{id:\d+}/post', 'bills:write', [
                'summary' => 'Post a supplier bill',
                'description' => 'Numbers it (BIL-2026-0001), freezes it and posts it: each line\'s cost and the tax to reclaim are debited, We owe suppliers credited. With a bill route it goes for approval first.',
                'tags' => $tags,
                'responses' => [200 => self::json('The bill', $bill)] + $notFound + $refused + self::auth(),
            ]),
            self::route('POST', '/api/accounting/bills/{id:\d+}/cancel', 'bills:write', [
                'summary' => 'Cancel a draft bill',
                'tags' => $tags,
                'responses' => [200 => self::json('The bill', $bill)] + $notFound + $refused + self::auth(),
            ]),
            self::route('POST', '/api/accounting/bills/{id:\d+}/credits', 'bills:write', [
                'summary' => 'Record a supplier credit for a bill',
                'description' => 'Posts the mirror of all or some lines (SCR-2026-0001) and settles what the bill still owes.',
                'tags' => $tags,
                'request' => 'AccountingCreditNoteRequest',
                'responses' => [201 => self::json('The supplier credit', $bill), 200 => self::json('The same credit, already made with this key', $bill)] + $notFound + $refused + self::auth(),
            ]),
            self::route('POST', '/api/accounting/payments-made', 'payments:write', [
                'summary' => 'Record money paid to a supplier',
                'description' => 'Posts it at once: We owe suppliers is debited, the bank or cash credited, or Cheques we wrote for a cheque dated later until it clears (`/payments/{id}/deposit`). It settles the bills given.',
                'tags' => $tags,
                'request' => 'AccountingPaymentRequest',
                'responses' => [201 => self::json('The payment', self::data(SchemaBuilder::ref('AccountingPayment'))), 200 => self::json('The same payment, already recorded with this key', self::data(SchemaBuilder::ref('AccountingPayment')))] + $refused + self::auth(),
            ]),
            self::route('GET', '/api/accounting/reports/aged-payables', 'bills:read', [
                'summary' => 'Aged payables',
                'description' => 'What is owed to each supplier on a date, by days past due, with what they owe back beside it. Minor units.',
                'tags' => $tags,
                'parameters' => [
                    self::param('as_of', 'string', 'YYYY-MM-DD. Default: today.'),
                    self::param('contact_id', 'integer', 'One supplier only.'),
                ],
                'responses' => [200 => self::json('Aged payables', self::data(SchemaBuilder::ref('AccountingAgedReceivables')))] + self::auth(),
            ]),
            self::route('GET', '/api/accounting/suppliers/{id:\d+}/statement', 'bills:read', [
                'summary' => 'A supplier\'s statement',
                'description' => 'From the books: what was owed to them before `from`, every bill, credit and payment, a running balance and what is owed at `to`.',
                'tags' => $tags,
                'parameters' => [
                    self::param('from', 'string', 'YYYY-MM-DD. Default: the first of January.'),
                    self::param('to', 'string', 'YYYY-MM-DD. Default: today.'),
                ],
                'responses' => [200 => self::json('The statement', self::data(SchemaBuilder::ref('AccountingCustomerStatement')))] + $notFound + self::auth(),
            ]),
            self::route('GET', '/api/accounting/bill-settings', 'bills:read', [
                'summary' => 'Show the bill settings',
                'tags' => $tags,
                'responses' => [200 => self::json('The settings', self::data(SchemaBuilder::ref('AccountingBillSettings')))] + self::auth(),
            ]),
            self::route('PATCH', '/api/accounting/bill-settings', 'accounting:settings', [
                'summary' => 'Change the bill settings',
                'description' => 'The approval route bills travel before they are posted; null for none.',
                'tags' => $tags,
                'request' => 'AccountingBillSettings',
                'responses' => [200 => self::json('The settings', self::data(SchemaBuilder::ref('AccountingBillSettings')))] + $refused + self::auth(),
            ]),
        ];
    }

    /**
     * Phase A2b: payments received, allocations, cheques, transfers, aged
     * receivables and the customer statement.
     *
     * @param array<int, array<string, mixed>> $notFound
     * @param array<int, array<string, mixed>> $refused
     * @return list<array{method: string, path: string, requiredRole: ?string, requiredPermission: ?string, schema: array<string, mixed>}>
     */
    private static function paymentRoutes(array $notFound, array $refused): array
    {
        $tags = ['accounting'];
        $payment = self::data(SchemaBuilder::ref('AccountingPayment'));

        return [
            self::route('GET', '/api/accounting/payments', 'payments:read', [
                'summary' => 'List payments',
                'description' => 'Money received and transfers, newest first. `unallocated_minor` is what a payment has not settled yet, the customer\'s credit.',
                'tags' => $tags,
                'parameters' => [
                    self::param('direction', 'string', '`in` (received) or `transfer`.'),
                    self::param('contact_id', 'integer', 'One customer\'s payments.'),
                    self::param('method', 'string', '`cash`, `bank_transfer`, `cliq`, `card`, `cheque`, `whity_pay` or `other`.'),
                    self::param('cheque_status', 'string', '`waiting`, `cleared` or `bounced`.'),
                    self::param('from', 'string', 'Paid on or after, YYYY-MM-DD.'),
                    self::param('to', 'string', 'Paid on or before, YYYY-MM-DD.'),
                    self::param('q', 'string', 'Part of the number, the reference or the customer\'s name.'),
                    self::param('limit', 'integer', 'At most 200. Default 50.'),
                    self::param('offset', 'integer', 'Skip this many.'),
                ],
                'responses' => [200 => self::json('Payments', self::list('AccountingPayment'))] + self::auth(),
            ]),
            self::route('POST', '/api/accounting/payments', 'payments:write', [
                'summary' => 'Record money a customer paid',
                'description' => 'Posts it at once: the bank, cash, cheques to collect or the payment provider is debited and the customer owes less. It settles the invoices given; anything left stays as the customer\'s credit. A cheque dated later waits on Cheques to collect until it is deposited. `idempotency_key` records it once.',
                'tags' => $tags,
                'request' => 'AccountingPaymentRequest',
                'responses' => [201 => self::json('The payment', $payment), 200 => self::json('The same payment, already recorded with this key', $payment)] + $refused + self::auth(),
            ]),
            self::route('GET', '/api/accounting/payments/{id:\d+}', 'payments:read', [
                'summary' => 'Show a payment',
                'description' => 'With what it settled.',
                'tags' => $tags,
                'responses' => [200 => self::json('The payment', $payment)] + $notFound + self::auth(),
            ]),
            self::route('POST', '/api/accounting/payments/{id:\d+}/allocations', 'payments:write', [
                'summary' => 'Settle an invoice with a payment',
                'description' => 'Uses what the payment has left, up to what the invoice still owes, or `amount_minor`. Posts nothing: the customer\'s balance already moved.',
                'tags' => $tags,
                'request' => 'AccountingAllocationRequest',
                'responses' => [200 => self::json('The payment', $payment)] + $notFound + $refused + self::auth(),
            ]),
            self::route('POST', '/api/accounting/payments/{id:\d+}/deposit', 'payments:write', [
                'summary' => 'Deposit a waiting cheque',
                'description' => 'Moves it from Cheques to collect to the bank.',
                'tags' => $tags,
                'request' => 'AccountingChequeDepositRequest',
                'responses' => [200 => self::json('The payment', $payment)] + $notFound + $refused + self::auth(),
            ]),
            self::route('POST', '/api/accounting/payments/{id:\d+}/bounce', 'payments:write', [
                'summary' => 'Record a bounced cheque',
                'description' => 'Reverses its entries, reopens the invoices it paid and posts the bank\'s fee when there is one.',
                'tags' => $tags,
                'request' => 'AccountingChequeBounceRequest',
                'responses' => [200 => self::json('The payment', $payment)] + $notFound + $refused + self::auth(),
            ]),
            self::route('POST', '/api/accounting/transfers', 'payments:write', [
                'summary' => 'Move money between the workspace\'s own accounts',
                'description' => 'Cash to the bank, or a payment provider\'s payout from Money at payment providers to the bank.',
                'tags' => $tags,
                'request' => 'AccountingTransferRequest',
                'responses' => [201 => self::json('The transfer', $payment), 200 => self::json('The same transfer, already recorded with this key', $payment)] + $refused + self::auth(),
            ]),
            self::route('GET', '/api/accounting/reports/aged-receivables', 'invoices:read', [
                'summary' => 'Aged receivables',
                'description' => 'What each customer owes on a date, by days past due (not yet due, 1-30, 31-60, 61-90, over 90), with their credit beside it. Minor units.',
                'tags' => $tags,
                'parameters' => [
                    self::param('as_of', 'string', 'YYYY-MM-DD. Default: today.'),
                    self::param('contact_id', 'integer', 'One customer only.'),
                ],
                'responses' => [200 => self::json('Aged receivables', self::data(SchemaBuilder::ref('AccountingAgedReceivables')))] + self::auth(),
            ]),
            self::route('GET', '/api/accounting/customers/{id:\d+}/statement', 'invoices:read', [
                'summary' => 'A customer\'s statement',
                'description' => 'From the books: what they owed before `from`, every invoice, credit note, payment and bounced cheque on their account, a running balance and what they owe at `to`.',
                'tags' => $tags,
                'parameters' => [
                    self::param('from', 'string', 'YYYY-MM-DD. Default: the first of January.'),
                    self::param('to', 'string', 'YYYY-MM-DD. Default: today.'),
                ],
                'responses' => [200 => self::json('The statement', self::data(SchemaBuilder::ref('AccountingCustomerStatement')))] + $notFound + self::auth(),
            ]),
        ];
    }

    /**
     * Phase A2a: tax codes, sales invoices and credit notes.
     *
     * @param array<int, array<string, mixed>> $notFound
     * @param array<int, array<string, mixed>> $refused
     * @return list<array{method: string, path: string, requiredRole: ?string, requiredPermission: ?string, schema: array<string, mixed>}>
     */
    private static function invoiceRoutes(array $notFound, array $refused): array
    {
        $tags = ['accounting'];
        $invoice = self::data(SchemaBuilder::ref('AccountingInvoice'));

        return [
            self::route('GET', '/api/accounting/tax-codes', 'invoices:read', [
                'summary' => 'List the tax codes',
                'description' => 'The workspace\'s sales tax codes: `output` codes go on sales, `input` codes on purchases. An item\'s tax category picks the code.',
                'tags' => $tags,
                'parameters' => [
                    self::param('direction', 'string', '`output` (sales) or `input` (purchases).'),
                    self::param('archived', 'string', '`true` to include archived codes.'),
                ],
                'responses' => [200 => self::json('Tax codes', self::list('AccountingTaxCode'))] + self::auth(),
            ]),
            self::route('POST', '/api/accounting/tax-codes', 'accounting:settings', [
                'summary' => 'Add a tax code',
                'description' => 'The Jordan codes come with the books and can be renamed, pointed at another account or archived. A rate never changes: a new rate is a new code from the date it starts, and the old one ends with valid_to.',
                'tags' => $tags,
                'request' => 'AccountingTaxCodeRequest',
                'responses' => [201 => self::json('The tax code', self::data(SchemaBuilder::ref('AccountingTaxCode')))] + $refused + self::auth(),
            ]),
            self::route('PATCH', '/api/accounting/tax-codes/{id:\d+}', 'accounting:settings', [
                'summary' => 'Change a tax code',
                'description' => 'Its name, its account, its tax category and when it stops. Not its rate, direction or treatment.',
                'tags' => $tags,
                'request' => 'AccountingTaxCodeChanges',
                'responses' => [200 => self::json('The tax code', self::data(SchemaBuilder::ref('AccountingTaxCode')))] + $notFound + $refused + self::auth(),
            ]),
            self::route('POST', '/api/accounting/tax-codes/{id:\d+}/archive', 'accounting:settings', [
                'summary' => 'Archive a tax code',
                'description' => 'New lines can no longer use it; invoices that did keep it.',
                'tags' => $tags,
                'responses' => [200 => self::json('The tax code', self::data(SchemaBuilder::ref('AccountingTaxCode')))] + $notFound + self::auth(),
            ]),
            self::route('POST', '/api/accounting/tax-codes/{id:\d+}/restore', 'accounting:settings', [
                'summary' => 'Restore an archived tax code',
                'tags' => $tags,
                'responses' => [200 => self::json('The tax code', self::data(SchemaBuilder::ref('AccountingTaxCode')))] + $notFound + self::auth(),
            ]),
            self::route('GET', '/api/accounting/invoices', 'invoices:read', [
                'summary' => 'List sales invoices and credit notes',
                'description' => 'Newest first. `due_minor` is what the customer still owes; `overdue` is true past the due date.',
                'tags' => $tags,
                'parameters' => [
                    self::param('kind', 'string', '`sale` or `sale_credit`. Default: both.'),
                    self::param('status', 'string', '`draft`, `waiting_approval`, `issued`, `partly_paid`, `paid`, `credited`, `cancelled`, or `overdue`.'),
                    self::param('contact_id', 'integer', 'One customer\'s invoices.'),
                    self::param('q', 'string', 'Part of the number or the customer\'s name.'),
                    self::param('from', 'string', 'Issued on or after, YYYY-MM-DD.'),
                    self::param('to', 'string', 'Issued on or before, YYYY-MM-DD.'),
                    self::param('limit', 'integer', 'At most 200. Default 50.'),
                    self::param('offset', 'integer', 'Skip this many.'),
                ],
                'responses' => [200 => self::json('Invoices', self::list('AccountingInvoice'))] + self::auth(),
            ]),
            self::route('POST', '/api/accounting/invoices', 'invoices:write', [
                'summary' => 'Draft a sales invoice',
                'description' => 'A draft posts nothing. Lines come from items (price, unit and tax category from the item) or are free text. Each line gets the tax code its tax category maps to unless one is given, and the Sales (or Services) account unless an income account is given. `idempotency_key` returns the same draft on a retry.',
                'tags' => $tags,
                'request' => 'AccountingInvoiceRequest',
                'responses' => [201 => self::json('The draft', $invoice), 200 => self::json('The same draft, already made with this key', $invoice)] + $refused + self::auth(),
            ]),
            self::route('GET', '/api/accounting/invoices/{id:\d+}', 'invoices:read', [
                'summary' => 'Show an invoice or credit note',
                'description' => 'With its lines and what has settled it (credit notes now, payments later).',
                'tags' => $tags,
                'responses' => [200 => self::json('The invoice', $invoice)] + $notFound + self::auth(),
            ]),
            self::route('PATCH', '/api/accounting/invoices/{id:\d+}', 'invoices:write', [
                'summary' => 'Change a draft invoice',
                'description' => 'Only a draft changes. `lines`, when given, replace every line. An issued invoice is credited instead.',
                'tags' => $tags,
                'request' => 'AccountingInvoiceChanges',
                'responses' => [200 => self::json('The draft', $invoice)] + $notFound + $refused + self::auth(),
            ]),
            self::route('POST', '/api/accounting/invoices/{id:\d+}/issue', 'invoices:write', [
                'summary' => 'Issue an invoice',
                'description' => 'Gives it the next number (INV-2026-0001), freezes it and posts it to the books: the customer owes the total, sales and the sales tax collected are credited. When the workspace has an invoice route it goes for approval first and comes back `waiting_approval`. A plugin may refuse it (409).',
                'tags' => $tags,
                'responses' => [200 => self::json('The invoice', $invoice)] + $notFound + $refused + self::auth(),
            ]),
            self::route('POST', '/api/accounting/invoices/{id:\d+}/cancel', 'invoices:write', [
                'summary' => 'Cancel a draft invoice',
                'description' => 'A draft, or one waiting for approval. An issued invoice is never cancelled; credit it.',
                'tags' => $tags,
                'responses' => [200 => self::json('The invoice', $invoice)] + $notFound + $refused + self::auth(),
            ]),
            self::route('POST', '/api/accounting/invoices/{id:\d+}/credit-notes', 'invoices:write', [
                'summary' => 'Credit an issued invoice',
                'description' => 'Issues a credit note at once for every line still not credited, or for the `lines` given, and posts the mirror of those lines. It settles what the invoice still owes; anything beyond stays as the customer\'s credit.',
                'tags' => $tags,
                'request' => 'AccountingCreditNoteRequest',
                'responses' => [201 => self::json('The credit note', $invoice), 200 => self::json('The same credit note, already made with this key', $invoice)] + $notFound + $refused + self::auth(),
            ]),
            self::route('GET', '/api/accounting/invoice-settings', 'invoices:read', [
                'summary' => 'Show the invoice settings',
                'tags' => $tags,
                'responses' => [200 => self::json('The settings', self::data(SchemaBuilder::ref('AccountingInvoiceSettings')))] + self::auth(),
            ]),
            self::route('PATCH', '/api/accounting/invoice-settings', 'accounting:settings', [
                'summary' => 'Change the invoice settings',
                'description' => 'The approval route invoices travel before they are issued (null for none), the default payment terms in days, and whether prices include tax by default.',
                'tags' => $tags,
                'request' => 'AccountingInvoiceSettings',
                'responses' => [200 => self::json('The settings', self::data(SchemaBuilder::ref('AccountingInvoiceSettings')))] + $refused + self::auth(),
            ]),
        ];
    }

    /** @return array<string, array<string, mixed>> */
    public static function components(): array
    {
        $names = ['type' => 'object', 'additionalProperties' => ['type' => 'string'], 'description' => 'The name in each language, such as `{"en": "Rent", "ar": "الإيجار"}`.'];
        $name = ['oneOf' => [self::str(), self::object(['en' => self::str(), 'ar' => self::str()], [])], 'description' => 'Text, or `{en, ar}`.'];
        $minor = static fn (string $what): array => self::int() + ['description' => $what . ' In minor units of the base currency.'];
        $line = self::object([
            'id' => self::int(),
            'line_no' => self::int(),
            'account_id' => self::int(),
            'account_code' => self::str(),
            'account_name' => self::str(),
            'debit_minor' => self::int(),
            'credit_minor' => self::int(),
            'contact_id' => self::int(true),
            'ou_id' => self::int(true),
            'description' => self::str(),
        ], ['id', 'line_no', 'account_id', 'account_code', 'account_name', 'debit_minor', 'credit_minor', 'contact_id', 'ou_id', 'description']);

        return [
            'AccountingBooks' => self::object([
                'base_currency' => self::str(),
                'currency_fixed' => self::bool(),
                'tax_pack' => self::str(),
                'year_start_month' => self::int(),
                'lock_date' => self::str(true),
            ], ['base_currency', 'currency_fixed', 'tax_pack', 'year_start_month', 'lock_date']),
            'AccountingAccount' => self::object([
                'id' => self::int(),
                'parent_id' => self::int(true),
                'code' => self::str(),
                'name' => self::str(),
                'names' => $names,
                'type' => self::str() + ['enum' => ['asset', 'liability', 'equity', 'income', 'expense']],
                'subtype' => self::str(),
                'is_group' => self::bool(),
                'role' => self::str(true),
                'allows_manual' => self::bool(),
                'ou_id' => self::int(true),
                'currency' => self::str(true),
                'is_seeded' => self::bool(),
                'owner_plugin' => self::str(true),
                'archived_at' => self::str(true),
                'balance_minor' => $minor('Debits minus credits to date.'),
                'depth' => self::int(),
                'has_lines' => self::bool(),
            ], ['id', 'parent_id', 'code', 'name', 'names', 'type', 'subtype', 'is_group', 'role', 'allows_manual', 'archived_at', 'balance_minor']),
            'AccountingAccountRequest' => self::object([
                'code' => self::str() + ['description' => 'Up to 20 letters, digits, dots or dashes, unique in the chart, such as `6150`.'],
                'name' => $name,
                'parent_id' => self::int(true) + ['description' => 'The heading it sits under; its type is taken from it.'],
                'type' => self::str() + ['enum' => ['asset', 'liability', 'equity', 'income', 'expense'], 'description' => 'Needed only for an account at the top of the chart.'],
                'subtype' => self::str() + ['description' => '`bank`, `cash`, `receivable`, `payable`, `tax`, `inventory`, `cost_of_sales`, `fixed_asset`, `current_asset`, `current_liability`, `long_term_liability` or `other` (the default).'],
                'is_group' => self::bool() + ['description' => 'True for a heading that holds other accounts and no lines.'],
                'allows_manual' => self::bool() + ['description' => 'False when only documents (invoices, bills) may post to it. Default true.'],
            ], ['code', 'name']),
            'AccountingAccountChanges' => self::object([
                'code' => self::str() + ['description' => 'A new code, while the account has no entries.'],
                'name' => $name,
                'parent_id' => self::int(true) + ['description' => 'Another heading of the same type, or null for the top.'],
                'subtype' => self::str() + ['description' => 'See AccountingAccountRequest.'],
                'allows_manual' => self::bool() + ['description' => 'Whether people may write lines on it by hand.'],
            ], []),
            'AccountingJournal' => self::object([
                'id' => self::int(),
                'code' => self::str(),
                'name' => self::str(),
                'names' => $names,
                'kind' => self::str() + ['enum' => ['sale', 'purchase', 'bank', 'cash', 'general', 'stock', 'opening', 'plugin']],
                'default_account_id' => self::int(true),
                'owner_plugin' => self::str(true),
                'archived_at' => self::str(true),
            ], ['id', 'code', 'name', 'names', 'kind', 'default_account_id', 'owner_plugin', 'archived_at']),
            'AccountingEntry' => self::object([
                'id' => self::int(),
                'journal_id' => self::int(),
                'journal_code' => self::str(),
                'period_id' => self::int(),
                'number' => self::str() + ['description' => 'Such as `GEN-2026-0042`.'],
                'date' => self::str(),
                'memo' => self::str(),
                'source_type' => self::str() + ['description' => '`manual`, `opening`, `reversal`, or a plugin\'s `<plugin>.<kind>`.'],
                'source_id' => self::int(true),
                'reverses_entry_id' => self::int(true),
                'reversed_by_entry_id' => self::int(true),
                'currency' => self::str(),
                'total_minor' => $minor('The sum of the debits.'),
                'posted_by_profile_id' => self::int(true),
                'agent_id' => self::int(true),
                'on_behalf_of_profile_id' => self::int(true),
                'hash' => self::str() + ['description' => 'SHA-256 over the entry and the hash of the entry before it in the workspace.'],
                'prev_hash' => self::str(),
                'posted_at' => self::str(),
                'lines' => ['type' => 'array', 'items' => $line],
            ], ['id', 'journal_id', 'journal_code', 'number', 'date', 'memo', 'source_type', 'currency', 'total_minor', 'hash']),
            'AccountingReverseRequest' => self::object([
                'note' => self::str() + ['description' => 'Why, shown as the memo. Up to 500 characters.'],
                'date' => self::str() + ['description' => 'The reversal\'s date, YYYY-MM-DD, in an open month. Optional.'],
            ], []),
            'AccountingManualEntry' => self::object([
                'id' => self::int(),
                'journal_id' => self::int(),
                'journal_code' => self::str(),
                'journal_kind' => self::str(),
                'date' => self::str(),
                'memo' => self::str(),
                'status' => self::str() + ['enum' => ['draft', 'posting', 'posted', 'cancelled']],
                'entry_id' => self::int(true),
                'currency' => self::str(),
                'debit_minor' => self::int(),
                'credit_minor' => self::int(),
                'balanced' => self::bool(),
                'created_by_profile_id' => self::int(true),
                'agent_id' => self::int(true),
                'on_behalf_of_profile_id' => self::int(true),
                'created_at' => self::str(),
                'updated_at' => self::str(),
                'lines' => ['type' => 'array', 'items' => $line],
            ], ['id', 'journal_id', 'journal_code', 'date', 'memo', 'status', 'entry_id', 'currency', 'debit_minor', 'credit_minor', 'balanced']),
            'AccountingManualEntryRequest' => self::object([
                'journal' => self::str() + ['description' => 'The journal\'s code: `GEN` (the default), `OPN` for opening balances, or a bank or cash journal.'],
                'date' => self::str() + ['description' => 'The accounting date, YYYY-MM-DD.'],
                'memo' => self::str() + ['description' => 'What the entry is for. Up to 500 characters.'],
                'lines' => ['type' => 'array', 'minItems' => 2, 'items' => self::object([
                    'account_id' => self::int() + ['description' => 'An account from the chart, not a heading.'],
                    'debit' => self::str() + ['description' => 'The debit in normal units, such as "12.500"; leave out on a credit line.'],
                    'credit' => self::str() + ['description' => 'The credit in normal units; leave out on a debit line.'],
                    'contact_id' => self::int(true) + ['description' => 'The customer or supplier this line is about.'],
                    'ou_id' => self::int(true) + ['description' => 'The branch (organisational unit).'],
                    'description' => self::str() + ['description' => 'A note for the line.'],
                ], ['account_id']), 'description' => 'Two or more lines whose debits equal their credits.'],
            ], ['date', 'lines']),
            'AccountingPeriod' => self::object([
                'id' => self::int(),
                'fiscal_year_id' => self::int(),
                'starts_on' => self::str(),
                'ends_on' => self::str(),
                'status' => self::str() + ['enum' => ['open', 'closed', 'locked']],
                'closed_at' => self::str(true),
                'closed_by_profile_id' => self::int(true),
                'reopened_at' => self::str(true),
                'reopened_by_profile_id' => self::int(true),
                'reopen_reason' => self::str(),
                'year_starts_on' => self::str(),
                'year_ends_on' => self::str(),
            ], ['id', 'starts_on', 'ends_on', 'status']),
            'AccountingReopenRequest' => self::object([
                'reason' => self::str() + ['description' => 'Why the month is reopened, kept on the month. Up to 500 characters.'],
            ], ['reason']),
            'AccountingTrialBalance' => self::object([
                'from' => self::str(),
                'to' => self::str(),
                'currency' => self::str(),
                'ou_id' => self::int(true),
                'rows' => ['type' => 'array', 'items' => self::object([
                    'account_id' => self::int(),
                    'code' => self::str(),
                    'name' => self::str(),
                    'names' => $names,
                    'type' => self::str(),
                    'parent_id' => self::int(true),
                    'opening_debit_minor' => self::int(),
                    'opening_credit_minor' => self::int(),
                    'debit_minor' => self::int(),
                    'credit_minor' => self::int(),
                    'closing_debit_minor' => self::int(),
                    'closing_credit_minor' => self::int(),
                ], ['account_id', 'code', 'name', 'type', 'opening_debit_minor', 'opening_credit_minor', 'debit_minor', 'credit_minor', 'closing_debit_minor', 'closing_credit_minor'])],
                'totals' => self::object([
                    'opening_debit_minor' => self::int(),
                    'opening_credit_minor' => self::int(),
                    'debit_minor' => self::int(),
                    'credit_minor' => self::int(),
                    'closing_debit_minor' => self::int(),
                    'closing_credit_minor' => self::int(),
                ], ['debit_minor', 'credit_minor']),
                'balanced' => self::bool(),
            ], ['from', 'to', 'currency', 'rows', 'totals', 'balanced']),
            'AccountingStatement' => self::object([
                'account' => self::object(['id' => self::int(), 'code' => self::str(), 'name' => self::str(), 'names' => $names, 'type' => self::str()], ['id', 'code', 'name', 'type']),
                'from' => self::str(),
                'to' => self::str(),
                'currency' => self::str(),
                'opening_minor' => self::int(),
                'closing_minor' => self::int(),
                'lines' => ['type' => 'array', 'items' => self::object([
                    'line_id' => self::int(),
                    'entry_id' => self::int(),
                    'number' => self::str(),
                    'date' => self::str(),
                    'memo' => self::str(),
                    'description' => self::str(),
                    'debit_minor' => self::int(),
                    'credit_minor' => self::int(),
                    'balance_minor' => self::int(),
                    'contact_id' => self::int(true),
                    'ou_id' => self::int(true),
                    'source_type' => self::str(),
                    'source_id' => self::int(true),
                ], ['line_id', 'entry_id', 'number', 'date', 'debit_minor', 'credit_minor', 'balance_minor'])],
                'next' => ['type' => 'object', 'nullable' => true, 'properties' => ['after_date' => self::str(), 'after_line' => self::int()]],
            ], ['account', 'from', 'to', 'currency', 'opening_minor', 'closing_minor', 'lines', 'next']),
        ] + self::invoiceComponents($names) + self::paymentComponents() + self::billComponents() + self::expenseComponents() + self::stockComponents();
    }

    /**
     * @param array<string, mixed> $names
     * @return array<string, array<string, mixed>>
     */
    private static function invoiceComponents(array $names): array
    {
        $date = static fn (bool $nullable = false): array => self::str($nullable) + ['description' => 'YYYY-MM-DD.'];
        $lineIn = self::object([
            'variant_id' => self::int(true) + ['description' => 'An item. Its name, price, unit and tax category are used unless given.'],
            'description' => self::str() + ['description' => 'What was sold. Needed for a line without an item.'],
            'quantity' => self::str() + ['description' => 'In normal units, such as "2.5". Default 1.'],
            'unit' => self::str() + ['description' => 'An item\'s unit code (box, kg). Default: its base unit.'],
            'unit_price_minor' => self::int() + ['description' => 'The price of one, in minor units.'],
            'discount_bp' => self::int() + ['description' => 'Discount in basis points, 0 to 10000 (1000 = 10%).'],
            'tax_code' => ['oneOf' => [self::int(), self::str()], 'description' => 'A tax code by id or code (JO-GST-16). Default: from the tax category.'],
            'tax_category' => self::str() + ['description' => '`standard`, `zero`, `exempt`, or a category a code the workspace added serves. Default: the item\'s, else standard.'],
            'account_id' => self::int(true) + ['description' => 'An income account. Default: Sales, or Services for a service item.'],
            'account_code' => self::str() + ['description' => 'The income account by its code, instead of account_id.'],
            'ou_id' => self::int(true) + ['description' => 'The branch (organisational unit) the sale belongs to.'],
        ], []);
        $head = [
            'contact_id' => self::int() + ['description' => 'The customer: a contact marked as a customer.'],
            'issue_date' => $date() + ['description' => 'YYYY-MM-DD. Default: today.'],
            'supply_date' => $date(true),
            'due_date' => $date(true) + ['description' => 'YYYY-MM-DD. Default: the issue date plus the payment terms.'],
            'payment_terms_days' => self::int() + ['description' => 'Days from issue to due, 0 to 365. Default: the workspace\'s.'],
            'prices_include_tax' => self::bool() + ['description' => 'Whether the prices typed include tax. Default: the workspace\'s setting.'],
            'notes' => self::str() + ['description' => 'Printed on the invoice. Up to 2000 characters.'],
            'lines' => ['type' => 'array', 'items' => $lineIn, 'description' => '1 to 300 lines.'],
        ];

        return [
            'AccountingTaxCode' => self::object([
                'id' => self::int(),
                'code' => self::str(),
                'name' => self::str(),
                'names' => $names,
                'pack' => self::str(),
                'direction' => self::str() + ['enum' => ['output', 'input']],
                'rate_bp' => self::int() + ['description' => 'Basis points: 1600 is 16%.'],
                'treatment' => self::str(),
                'category' => self::str(),
                'account_id' => self::int(true),
                'return_box' => self::str(),
                'einvoice_category' => self::str(),
                'valid_from' => self::str(true),
                'valid_to' => self::str(true),
                'is_seeded' => self::bool(),
                'owner_plugin' => self::str(true),
                'archived' => self::bool(),
            ], ['id', 'code', 'name', 'direction', 'rate_bp', 'treatment', 'category']),
            'AccountingTaxCodeRequest' => self::object([
                'code' => self::str() + ['description' => 'Up to 30 letters, digits, dots, dashes or underscores, such as JO-GST-8.'],
                'name' => ['oneOf' => [self::str(), self::object(['en' => self::str(), 'ar' => self::str()], [])], 'description' => 'Text, or `{en, ar}`.'],
                'direction' => self::str() + ['enum' => ['output', 'input'], 'description' => '`output` for sales, `input` for purchases.'],
                'rate_bp' => self::int() + ['description' => 'Basis points, 0 to 10000: 1600 is 16%. Never changes once made.'],
                'treatment' => self::str() + ['enum' => ['standard', 'reduced', 'zero', 'exempt', 'out_of_scope', 'reverse_charge'], 'description' => 'Default: standard above 0, zero at 0.'],
                'category' => self::str() + ['description' => 'The item tax category it serves, such as reduced_8.'],
                'account_id' => self::int(true) + ['description' => 'Where its tax posts. Default: Sales tax we collected (output) or Sales tax we can reclaim (input).'],
                'valid_from' => self::str(true) + ['description' => 'YYYY-MM-DD, the first day it applies.'],
                'valid_to' => self::str(true) + ['description' => 'YYYY-MM-DD, the last day it applies.'],
            ], ['code', 'name', 'direction', 'rate_bp']),
            'AccountingTaxCodeChanges' => self::object([
                'name' => ['oneOf' => [self::str(), self::object(['en' => self::str(), 'ar' => self::str()], [])], 'description' => 'Text, or `{en, ar}`.'],
                'account_id' => self::int(true) + ['description' => 'Where its tax posts; null for the default account.'],
                'category' => self::str() + ['description' => 'The item tax category it serves.'],
                'valid_to' => self::str(true) + ['description' => 'YYYY-MM-DD, the last day it applies; null for no end.'],
            ], []),
            'AccountingInvoiceLine' => self::object([
                'id' => self::int(),
                'line_no' => self::int(),
                'variant_id' => self::int(true),
                'unit_id' => self::int(true),
                'description' => self::str(),
                'quantity' => self::int() + ['description' => 'In steps of quantity_decimals: 25 with one decimal is 2.5.'],
                'quantity_decimals' => self::int(),
                'unit_price_minor' => self::int(),
                'discount_bp' => self::int(),
                'net_minor' => self::int(),
                'tax_code_id' => self::int(),
                'tax_code' => self::str(),
                'tax_rate_bp' => self::int(),
                'tax_minor' => self::int(),
                'total_minor' => self::int(),
                'account_id' => self::int(),
                'account_code' => self::str(),
                'account_name' => self::str(),
                'ou_id' => self::int(true),
                'credits_line_id' => self::int(true),
            ], ['id', 'line_no', 'description', 'quantity', 'quantity_decimals', 'unit_price_minor', 'net_minor', 'tax_code_id', 'tax_minor', 'total_minor', 'account_id']),
            'AccountingInvoice' => self::object([
                'id' => self::int(),
                'kind' => self::str() + ['enum' => ['sale', 'sale_credit', 'purchase', 'purchase_credit']],
                'number' => self::str(true),
                'supplier_number' => self::str(true) + ['description' => 'On a bill: the supplier\'s own invoice number.'],
                'status' => self::str() + ['enum' => ['draft', 'waiting_approval', 'issued', 'partly_paid', 'paid', 'credited', 'cancelled']],
                'contact_id' => self::int(),
                'contact_name' => self::str(),
                'issue_date' => self::str(),
                'supply_date' => self::str(true),
                'due_date' => self::str(true),
                'payment_terms_days' => self::int(),
                'overdue' => self::bool(),
                'currency' => self::str(),
                'prices_include_tax' => self::bool(),
                'subtotal_minor' => self::int(),
                'discount_minor' => self::int(),
                'tax_minor' => self::int(),
                'total_minor' => self::int(),
                'paid_minor' => self::int(),
                'credited_minor' => self::int(),
                'due_minor' => self::int() + ['description' => 'What is still owed.'],
                'credits_invoice_id' => self::int(true) + ['description' => 'On a credit note: the invoice it credits.'],
                'entry_id' => self::int(true) + ['description' => 'The journal entry it posted, once issued.'],
                'route_id' => self::int(true),
                'einvoice_status' => self::str() + ['enum' => ['none', 'pending', 'accepted', 'rejected', 'not_required']],
                'einvoice_uuid' => self::str(true),
                'einvoice_ref' => self::str(true),
                'einvoice_qr' => self::str(true),
                'einvoice_message' => self::str(),
                'notes' => self::str(),
                'external_ref' => self::str(true),
                'quote_id' => self::int(true),
                'issued_at' => self::str(true),
                'issued_by_profile_id' => self::int(true),
                'created_by_profile_id' => self::int(true),
                'agent_id' => self::int(true),
                'on_behalf_of_profile_id' => self::int(true),
                'owner_plugin' => self::str(true),
                'claimant_profile_id' => self::int(true) + ['description' => 'On an expense claim: the person who paid and is owed it.'],
                'receipt_stored_file_id' => self::int(true) + ['description' => 'The photo of the receipt.'],
                'created_at' => self::str(),
                'lines' => ['type' => 'array', 'items' => SchemaBuilder::ref('AccountingInvoiceLine')],
                'allocations' => ['type' => 'array', 'items' => self::object([
                    'id' => self::int(),
                    'invoice_id' => self::int(),
                    'credit_invoice_id' => self::int(true),
                    'credit_number' => self::str(true),
                    'payment_id' => self::int(true),
                    'amount_minor' => self::int(),
                    'allocated_on' => self::str(),
                ], ['id', 'invoice_id', 'amount_minor', 'allocated_on'])],
            ], ['id', 'kind', 'number', 'status', 'contact_id', 'issue_date', 'currency', 'subtotal_minor', 'tax_minor', 'total_minor', 'due_minor']),
            'AccountingInvoiceRequest' => self::object($head + [
                'idempotency_key' => self::str() + ['description' => 'Retrying with the same key returns the same draft.'],
            ], ['contact_id', 'lines']),
            'AccountingInvoiceChanges' => self::object($head, []),
            'AccountingCreditNoteRequest' => self::object([
                'lines' => ['type' => 'array', 'items' => self::object([
                    'line_id' => self::int(),
                    'quantity' => self::str() + ['description' => 'How much to credit, in normal units. Default: all that is left.'],
                ], ['line_id']), 'description' => 'Leave out to credit everything still not credited.'],
                'reason' => self::str() + ['description' => 'Why, printed on the credit note. Up to 2000 characters.'],
                'issue_date' => $date() + ['description' => 'YYYY-MM-DD. Default: today.'],
                'idempotency_key' => self::str() + ['description' => 'Retrying with the same key returns the same credit note.'],
            ], []),
            'AccountingInvoiceSettings' => self::object([
                'invoice_route_template_id' => self::int(true) + ['description' => 'The route invoices travel before they are issued; null for none.'],
                'invoice_payment_terms_days' => self::int() + ['description' => 'Days from issue to due on a new invoice, 0 to 365.'],
                'prices_include_tax' => self::bool() + ['description' => 'Whether a new invoice\'s prices include tax.'],
            ], []),
        ];
    }

    /** @return array<string, array<string, mixed>> */
    private static function paymentComponents(): array
    {
        $buckets = [
            'not_due' => self::int() + ['description' => 'Not yet due.'],
            'days_1_30' => self::int(),
            'days_31_60' => self::int(),
            'days_61_90' => self::int(),
            'days_over_90' => self::int(),
            'credit_minor' => self::int() + ['description' => 'Money received or credited that settles nothing yet.'],
            'balance_minor' => self::int() + ['description' => 'What is owed, less the credit.'],
        ];

        return [
            'AccountingPayment' => self::object([
                'id' => self::int(),
                'direction' => self::str() + ['enum' => ['in', 'out', 'transfer']],
                'number' => self::str(),
                'contact_id' => self::int(true),
                'contact_name' => self::str(true),
                'paid_on' => self::str(),
                'amount_minor' => self::int(),
                'currency' => self::str(),
                'method' => self::str() + ['enum' => ['cash', 'bank_transfer', 'cliq', 'card', 'cheque', 'whity_pay', 'other']],
                'account_id' => self::int(),
                'from_account_id' => self::int(true),
                'reference' => self::str(),
                'cheque_number' => self::str(true),
                'cheque_bank' => self::str(true),
                'cheque_due_on' => self::str(true),
                'cheque_status' => ['type' => 'string', 'nullable' => true, 'enum' => ['waiting', 'cleared', 'bounced', null]],
                'fee_minor' => self::int(),
                'status' => self::str() + ['enum' => ['posted', 'bounced']],
                'allocated_minor' => self::int(),
                'unallocated_minor' => self::int() + ['description' => 'What it has not settled yet: the customer\'s credit.'],
                'notes' => self::str(),
                'entry_id' => self::int(true),
                'deposit_entry_id' => self::int(true),
                'bounce_entry_id' => self::int(true),
                'external_ref' => self::str(true),
                'created_by_profile_id' => self::int(true),
                'agent_id' => self::int(true),
                'on_behalf_of_profile_id' => self::int(true),
                'owner_plugin' => self::str(true),
                'created_at' => self::str(),
                'allocations' => ['type' => 'array', 'items' => self::object([
                    'id' => self::int(),
                    'invoice_id' => self::int(),
                    'invoice_number' => self::str(true),
                    'amount_minor' => self::int(),
                    'allocated_on' => self::str(),
                    'released' => self::bool() + ['description' => 'True when a bounced cheque released it.'],
                ], ['id', 'invoice_id', 'amount_minor', 'allocated_on', 'released'])],
            ], ['id', 'direction', 'number', 'paid_on', 'amount_minor', 'currency', 'method', 'status', 'unallocated_minor']),
            'AccountingPaymentRequest' => self::object([
                'contact_id' => self::int() + ['description' => 'The customer who paid.'],
                'paid_on' => self::str() + ['description' => 'YYYY-MM-DD. Default: today.'],
                'amount' => self::str() + ['description' => 'In normal units, such as "125.500".'],
                'amount_minor' => self::int() + ['description' => 'Or the amount in minor units.'],
                'method' => self::str() + ['enum' => ['cash', 'bank_transfer', 'cliq', 'card', 'cheque', 'whity_pay', 'other'], 'description' => 'Default: bank_transfer. It picks the account the money goes to.'],
                'account_id' => self::int() + ['description' => 'The bank or cash account it went to, instead of the method\'s.'],
                'reference' => self::str() + ['description' => 'The bank\'s or provider\'s reference.'],
                'cheque_number' => self::str() + ['description' => 'Needed for a cheque.'],
                'cheque_bank' => self::str() + ['description' => 'The bank the cheque is drawn on.'],
                'cheque_due_on' => self::str() + ['description' => 'YYYY-MM-DD. A later date keeps it on Cheques to collect until deposited.'],
                'fee_minor' => self::int() + ['description' => 'What a card or Whity Pay provider kept, in minor units.'],
                'invoice_id' => self::int() + ['description' => 'Settle this invoice with it.'],
                'allocations' => ['type' => 'array', 'description' => 'Or settle several invoices.', 'items' => self::object([
                    'invoice_id' => self::int(),
                    'amount_minor' => self::int() + ['description' => 'Default: all that fits.'],
                ], ['invoice_id'])],
                'notes' => self::str() + ['description' => 'Up to 2000 characters.'],
                'idempotency_key' => self::str() + ['description' => 'Retrying with the same key returns the same payment.'],
            ], ['contact_id']),
            'AccountingAllocationRequest' => self::object([
                'invoice_id' => self::int() + ['description' => 'The invoice to settle.'],
                'amount_minor' => self::int() + ['description' => 'Default: all that fits.'],
            ], ['invoice_id']),
            'AccountingChequeDepositRequest' => self::object([
                'deposited_on' => self::str() + ['description' => 'YYYY-MM-DD. Default: today.'],
                'account_id' => self::int() + ['description' => 'The bank account it went into. Default: Bank.'],
            ], []),
            'AccountingChequeBounceRequest' => self::object([
                'bounced_on' => self::str() + ['description' => 'YYYY-MM-DD. Default: today.'],
                'fee_minor' => self::int() + ['description' => 'The bank\'s fee, in minor units.'],
                'note' => self::str() + ['description' => 'Why, kept on the reversing entry.'],
            ], []),
            'AccountingTransferRequest' => self::object([
                'from_account_id' => self::int() + ['description' => 'Where the money left: cash, a bank, or Money at payment providers.'],
                'to_account_id' => self::int() + ['description' => 'Where it arrived.'],
                'amount' => self::str() + ['description' => 'In normal units, such as "500.000".'],
                'amount_minor' => self::int() + ['description' => 'Or the amount in minor units.'],
                'paid_on' => self::str() + ['description' => 'YYYY-MM-DD. Default: today.'],
                'reference' => self::str() + ['description' => 'The bank\'s or provider\'s reference.'],
                'notes' => self::str() + ['description' => 'Up to 2000 characters.'],
                'idempotency_key' => self::str() + ['description' => 'Retrying with the same key returns the same transfer.'],
            ], ['from_account_id', 'to_account_id']),
            'AccountingAgedReceivables' => self::object([
                'as_of' => self::str(),
                'currency' => self::str(),
                'rows' => ['type' => 'array', 'items' => self::object([
                    'contact_id' => self::int(),
                    'contact_name' => self::str(),
                ] + $buckets, ['contact_id', 'contact_name', 'not_due', 'balance_minor'])],
                'totals' => self::object($buckets, ['balance_minor']),
            ], ['as_of', 'currency', 'rows', 'totals']),
            'AccountingCustomerStatement' => self::object([
                'contact' => self::object(['id' => self::int(), 'name' => self::str()], ['id', 'name']),
                'from' => self::str(),
                'to' => self::str(),
                'currency' => self::str(),
                'opening_minor' => self::int(),
                'closing_minor' => self::int(),
                'lines' => ['type' => 'array', 'items' => self::object([
                    'line_id' => self::int(),
                    'entry_id' => self::int(),
                    'number' => self::str(),
                    'date' => self::str(),
                    'memo' => self::str(),
                    'source_type' => self::str(),
                    'source_id' => self::int(true),
                    'debit_minor' => self::int(),
                    'credit_minor' => self::int(),
                    'balance_minor' => self::int(),
                ], ['line_id', 'entry_id', 'number', 'date', 'debit_minor', 'credit_minor', 'balance_minor'])],
            ], ['contact', 'from', 'to', 'currency', 'opening_minor', 'closing_minor', 'lines']),
        ];
    }

    /** @return array<string, array<string, mixed>> */
    private static function billComponents(): array
    {
        $line = self::object([
            'variant_id' => self::int(true) + ['description' => 'An item bought. Its name, purchase price and tax category are used unless given.'],
            'description' => self::str() + ['description' => 'What was bought. Needed for a line without an item.'],
            'quantity' => self::str() + ['description' => 'In normal units, such as "2.5". Default 1.'],
            'unit' => self::str() + ['description' => 'An item\'s unit code. Default: its base unit.'],
            'unit_price_minor' => self::int() + ['description' => 'The price of one, in minor units.'],
            'discount_bp' => self::int() + ['description' => 'Discount in basis points, 0 to 10000.'],
            'tax_code' => ['oneOf' => [self::int(), self::str()], 'description' => 'An input tax code by id or code (JO-GST-IN-16). Default: from the tax category.'],
            'tax_category' => self::str() + ['description' => '`standard`, `zero`, `exempt`, or a category a code the workspace added serves.'],
            'account_id' => self::int(true) + ['description' => 'A cost or an asset bought, such as Equipment. Default: Other costs.'],
            'account_code' => self::str() + ['description' => 'The account by its code, instead of account_id.'],
            'ou_id' => self::int(true) + ['description' => 'The branch the cost belongs to.'],
        ], []);
        $head = [
            'contact_id' => self::int() + ['description' => 'The supplier: a contact marked as a supplier.'],
            'supplier_number' => self::str() + ['description' => 'The supplier\'s own invoice number.'],
            'issue_date' => self::str() + ['description' => 'The bill\'s date, YYYY-MM-DD. Default: today.'],
            'due_date' => self::str(true) + ['description' => 'YYYY-MM-DD. Default: the date plus the payment terms.'],
            'payment_terms_days' => self::int() + ['description' => 'Days from the bill\'s date to due, 0 to 365.'],
            'prices_include_tax' => self::bool() + ['description' => 'Whether the prices typed include tax.'],
            'notes' => self::str() + ['description' => 'Up to 2000 characters.'],
            'lines' => ['type' => 'array', 'items' => $line, 'description' => '1 to 300 lines.'],
        ];

        return [
            'AccountingBillRequest' => self::object($head + [
                'idempotency_key' => self::str() + ['description' => 'Retrying with the same key returns the same draft.'],
            ], ['contact_id', 'lines']),
            'AccountingBillChanges' => self::object($head, []),
            'AccountingBillSettings' => self::object([
                'bill_route_template_id' => self::int(true) + ['description' => 'The route bills travel before they are posted; null for none.'],
            ], []),
        ];
    }

    /** @return array<string, array<string, mixed>> */
    private static function expenseComponents(): array
    {
        return [
            'AccountingExpenseClaimRequest' => self::object([
                'date' => self::str() + ['description' => 'When it was paid, YYYY-MM-DD. Default: today.'],
                'lines' => ['type' => 'array', 'description' => '1 to 50 lines.', 'items' => self::object([
                    'description' => self::str() + ['description' => 'What it was: "Taxi to the bank".'],
                    'amount' => self::str() + ['description' => 'What was paid, tax included, such as "12.500".'],
                    'amount_minor' => self::int() + ['description' => 'Or the amount in minor units.'],
                    'tax_category' => self::str() + ['description' => '`standard` (default), `zero` or `exempt`.'],
                    'tax_code' => ['oneOf' => [self::int(), self::str()], 'description' => 'An input tax code instead of the category.'],
                    'account_id' => self::int() + ['description' => 'The cost it was. Default: Other costs.'],
                    'account_code' => self::str() + ['description' => 'The cost by its code, instead of account_id.'],
                ], ['description'])],
                'notes' => self::str() + ['description' => 'Where and why. Up to 2000 characters.'],
                'receipt_stored_file_id' => self::int() + ['description' => 'The photo of the receipt, a file uploaded to the workspace.'],
                'idempotency_key' => self::str() + ['description' => 'Retrying with the same key returns the same record.'],
            ], ['lines']),
            'AccountingReimburseRequest' => self::object([
                'method' => self::str() + ['description' => '`bank_transfer` (default), `cliq`, `cash` or `cheque`.'],
                'account_id' => self::int() + ['description' => 'The bank or cash account it is paid from.'],
                'paid_on' => self::str() + ['description' => 'YYYY-MM-DD. Default: today.'],
                'reference' => self::str() + ['description' => 'The bank\'s reference.'],
            ], []),
            'AccountingPettyCash' => self::object([
                'account_id' => self::int(),
                'code' => self::str(),
                'balance_minor' => self::int() + ['description' => 'What is in petty cash, in minor units.'],
                'currency' => self::str(),
            ], ['account_id', 'code', 'balance_minor', 'currency']),
        ];
    }

    /** @return array<string, array<string, mixed>> */
    private static function stockComponents(): array
    {
        return [
            'AccountingStockStatus' => self::object([
                'stock_in_books' => self::bool(),
                'since' => self::str(true) + ['description' => 'When stock first posted to the books since it was last turned on.'],
                'currency' => self::str(),
                'stock_value_minor' => self::int() + ['description' => 'The stock\'s value at its moving average cost.'],
                'stock_account_minor' => self::int() + ['description' => 'What the Stock account says.'],
                'items_without_cost' => self::int(),
                'waiting' => self::int() + ['description' => 'Stock changes that posted nothing for want of a cost.'],
            ], ['stock_in_books', 'currency', 'stock_value_minor', 'stock_account_minor']),
            'AccountingStockSwitch' => self::object([
                'stock_in_books' => self::bool() + ['description' => 'Whether stock posts to the books.'],
            ], ['stock_in_books']),
            'AccountingStockOpening' => self::object([
                'date' => self::str() + ['description' => 'YYYY-MM-DD, in an open month. Default: today.'],
            ], []),
        ];
    }

    /** @return array<string, mixed> */
    private static function list(string $ref): array
    {
        return self::object(['data' => ['type' => 'array', 'items' => SchemaBuilder::ref($ref)]], ['data']);
    }

    /**
     * @param array<string, mixed> $schema
     * @return array{method: string, path: string, requiredRole: ?string, requiredPermission: ?string, schema: array<string, mixed>}
     */
    private static function route(string $method, string $path, string $permission, array $schema): array
    {
        return ['method' => $method, 'path' => $path, 'requiredRole' => null, 'requiredPermission' => $permission, 'schema' => $schema];
    }

    /** @return array<string, mixed> */
    private static function param(string $name, string $type, string $description): array
    {
        return ['name' => $name, 'in' => 'query', 'required' => false, 'description' => $description, 'schema' => ['type' => $type]];
    }

    /**
     * @param array<string, mixed> $schema
     * @return array<string, mixed>
     */
    private static function json(string $description, array $schema): array
    {
        return ['description' => $description, 'content' => ['application/json' => ['schema' => $schema]]];
    }

    /** @return array<string, mixed> */
    private static function error(string $description): array
    {
        return ['description' => $description, 'content' => ['application/json' => ['schema' => SchemaBuilder::ref('Error')]]];
    }

    /** @return array<int, array<string, mixed>> */
    private static function auth(): array
    {
        return [401 => self::error('Missing or invalid authentication'), 403 => self::error('Insufficient permissions')];
    }

    /**
     * @param array<string, mixed> $item
     * @return array<string, mixed>
     */
    private static function data(array $item): array
    {
        return self::object(['data' => $item], ['data']);
    }

    /**
     * @param array<string, mixed> $properties
     * @param list<string> $required
     * @return array<string, mixed>
     */
    private static function object(array $properties, array $required): array
    {
        $schema = ['type' => 'object', 'properties' => $properties];
        if ($required !== []) {
            $schema['required'] = $required;
        }

        return $schema;
    }

    /** @return array<string, mixed> */
    private static function int(bool $nullable = false): array
    {
        return $nullable ? ['type' => 'integer', 'nullable' => true] : ['type' => 'integer'];
    }

    /** @return array<string, mixed> */
    private static function str(bool $nullable = false): array
    {
        return $nullable ? ['type' => 'string', 'nullable' => true] : ['type' => 'string'];
    }

    /** @return array<string, mixed> */
    private static function bool(): array
    {
        return ['type' => 'boolean'];
    }
}
