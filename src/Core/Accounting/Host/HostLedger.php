<?php

declare(strict_types=1);

namespace Whity\Core\Accounting\Host;

use Whity\Core\Accounting\Ledger as CoreLedger;
use Whity\Core\RBAC\CorePermissions;
use Whity\Sdk\Accounting\AccountingRejected;
use Whity\Sdk\Accounting\Entry;
use Whity\Sdk\Accounting\EntryRequest;
use Whity\Sdk\Accounting\EntryRequestLine;
use Whity\Sdk\Accounting\Ledger;
use Whity\Sdk\PluginInterface;

/**
 * Core's ledger for plugins (SDK 1.131, WHIT-1318), in the workspace the call
 * runs in.
 *
 * WHO POSTS. The instance in the container is unbound: it reads, and refuses
 * a write with reason `use_plugin_context`, because anyone can resolve it.
 * {@see self::for()} gives a copy bound to a plugin OBJECT the loader loaded;
 * the plugin context hands that copy out as `$ctx->accounting()->ledger()`.
 * Its entries are stored as `<slug>.<kind>`, and its idempotency keys are
 * namespaced the same way, so no plugin can post as core or as another
 * plugin, nor collide with another's keys.
 */
final class HostLedger implements Ledger
{
    public function __construct(
        private readonly CoreLedger $ledger,
        private readonly HostAccountingGuard $guard,
        private readonly ?string $source = null,
    ) {
    }

    /** A copy that posts as `$plugin`. Host-side only. */
    public function for(PluginInterface $plugin): self
    {
        return new self($this->ledger, $this->guard, HostAccountingGuard::slugOf($plugin));
    }

    public function post(EntryRequest $request): Entry
    {
        $source = $this->sourceType($request->sourceType);
        $tenantId = $this->guard->workspace();
        $this->guard->writer($tenantId, $request->actingProfileId, CorePermissions::ACCOUNTING_POST);
        $lines = array_map(static fn (EntryRequestLine $line): array => [
            'account_id' => $line->accountId,
            'debit_minor' => $line->debitMinor,
            'credit_minor' => $line->creditMinor,
            'contact_id' => $line->contactId,
            'ou_id' => $line->ouId,
            'description' => $line->description,
        ], $request->lines);

        $result = HostAccountingGuard::run(fn (): array => $this->ledger->post($tenantId, $request->actingProfileId, [
            'journal' => $request->journal,
            'date' => $request->date,
            'memo' => $request->memo,
            'lines' => $lines,
            'source_type' => $source,
            'source_id' => $request->sourceId,
            'idempotency_key' => $request->idempotencyKey === null ? null : $source . ':' . $request->idempotencyKey,
        ]));

        return Entry::fromArray($result['entry']);
    }

    public function reverse(int $entryId, int $actingProfileId, ?string $note = null, ?string $date = null): Entry
    {
        $source = $this->sourceType('reversal');
        $tenantId = $this->guard->workspace();
        $this->guard->writer($tenantId, $actingProfileId, CorePermissions::ACCOUNTING_POST);
        $result = HostAccountingGuard::run(fn (): array => $this->ledger->reverse($tenantId, $entryId, $actingProfileId, $note ?? '', $date, $source));

        return Entry::fromArray($result['entry']);
    }

    public function entry(int $entryId): Entry
    {
        $tenantId = $this->guard->workspace();

        return Entry::fromArray(HostAccountingGuard::run(fn (): array => $this->ledger->entry($tenantId, $entryId)));
    }

    public function entries(array $filter = [], int $limit = 50): array
    {
        $tenantId = $this->guard->workspace();
        $rows = HostAccountingGuard::run(fn (): array => $this->ledger->entries($tenantId, [
            'journal_id' => $filter['journal_id'] ?? null,
            'account_id' => $filter['account_id'] ?? null,
            'source_type' => $filter['source_type'] ?? null,
            'source_id' => $filter['source_id'] ?? null,
            'from' => $filter['from'] ?? null,
            'to' => $filter['to'] ?? null,
            'before_id' => $filter['before_id'] ?? null,
        ], $limit));

        return array_map(static fn (array $row): Entry => Entry::fromArray($row), $rows);
    }

    public function balance(int $accountId, string $asOf, ?int $ouId = null): int
    {
        $tenantId = $this->guard->workspace();

        return HostAccountingGuard::run(fn (): int => $this->ledger->balance($tenantId, $accountId, $asOf, $ouId));
    }

    private function sourceType(string $kind): string
    {
        if ($this->source === null) {
            throw HostAccountingGuard::useContext('$ctx->accounting()->ledger()');
        }
        $kind = strtolower(trim($kind));
        if (preg_match('/^[a-z][a-z0-9_]{0,40}$/', $kind) !== 1) {
            throw new AccountingRejected('sourceType is lower-case letters, digits and _, such as "payroll_run".', 422, 'sourceType', AccountingRejected::INVALID);
        }

        return $this->source . '.' . $kind;
    }
}
