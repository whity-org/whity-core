<?php

declare(strict_types=1);

namespace Whity\Sdk\Accounting;

/**
 * The workspace's chart of accounts, for a plugin (SDK 1.131, WHIT-1318,
 * register AC.8).
 *
 * Reach it through the plugin context, `$ctx->accounting()->accounts()`, or
 * the container (`\Whity\app(Accounts::class)`). Both read. Only the context's
 * copy adds accounts: they belong to your plugin (`ownerPlugin`), and the
 * container's `create()` refuses with reason `use_plugin_context`.
 *
 * It works in the workspace the call runs in; with none, it throws
 * {@see AccountingRejected} `no_workspace`. The first call in a workspace sets
 * its books up with the default chart.
 */
interface Accounts
{
    /**
     * Every account in tree order.
     *
     * @return list<Account>
     * @throws AccountingRejected
     */
    public function list(bool $includeArchived = false): array;

    /** @throws AccountingRejected `not_found` when it isn't in this workspace's chart */
    public function get(int $accountId): Account;

    /** @throws AccountingRejected */
    public function byCode(string $code): ?Account;

    /**
     * The account holding a role the books use: `receivable`, `payable`,
     * `sales_tax_output`, `sales_tax_input`, `inventory`, `cost_of_sales`,
     * `bank`, `cash`, `retained_earnings`, `opening_balance`, `rounding` and
     * the others in design section 32 §3.4.
     *
     * @throws AccountingRejected
     */
    public function byRole(string $role): ?Account;

    /**
     * Adds an account your plugin owns, acting as a person holding
     * `accounting:settings`. `name` is text or `['en' => ..., 'ar' => ...]`.
     * Give a heading as `parentId` (its type is taken), or a `type` at the top.
     *
     * @param string|array<string, string> $name
     * @throws AccountingRejected `conflict` for a code already used
     */
    public function create(int $actingProfileId, string $code, string|array $name, ?int $parentId = null, ?string $type = null, string $subtype = 'other'): Account;
}
