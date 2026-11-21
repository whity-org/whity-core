<?php

declare(strict_types=1);

namespace Whity\Core\Accounting\Host;

use Whity\Core\Accounting\ChartOfAccounts;
use Whity\Core\RBAC\CorePermissions;
use Whity\Sdk\Accounting\Account;
use Whity\Sdk\Accounting\Accounts;
use Whity\Sdk\PluginInterface;

/**
 * The chart of accounts for plugins (SDK 1.131, WHIT-1318), in the workspace
 * the call runs in. The container's copy reads; {@see self::for()} gives a
 * copy bound to a loaded plugin, whose new accounts belong to that plugin.
 */
final class HostAccounts implements Accounts
{
    public function __construct(
        private readonly ChartOfAccounts $chart,
        private readonly HostAccountingGuard $guard,
        private readonly ?string $plugin = null,
    ) {
    }

    /** A copy whose new accounts belong to `$plugin`. Host-side only. */
    public function for(PluginInterface $plugin): self
    {
        return new self($this->chart, $this->guard, HostAccountingGuard::slugOf($plugin));
    }

    public function list(bool $includeArchived = false): array
    {
        $tenantId = $this->guard->workspace();

        return array_map(static fn (array $row): Account => Account::fromArray($row), $this->chart->list($tenantId, $includeArchived));
    }

    public function get(int $accountId): Account
    {
        $tenantId = $this->guard->workspace();

        return Account::fromArray(HostAccountingGuard::run(fn (): array => $this->chart->get($tenantId, $accountId)));
    }

    public function byCode(string $code): ?Account
    {
        $tenantId = $this->guard->workspace();
        $row = $this->chart->byCode($tenantId, $code);

        return $row === null ? null : Account::fromArray($row);
    }

    public function byRole(string $role): ?Account
    {
        $tenantId = $this->guard->workspace();
        $row = $this->chart->byRole($tenantId, $role);

        return $row === null ? null : Account::fromArray($row);
    }

    public function create(int $actingProfileId, string $code, string|array $name, ?int $parentId = null, ?string $type = null, string $subtype = 'other'): Account
    {
        if ($this->plugin === null) {
            throw HostAccountingGuard::useContext('$ctx->accounting()->accounts()');
        }
        $tenantId = $this->guard->workspace();
        $this->guard->writer($tenantId, $actingProfileId, CorePermissions::ACCOUNTING_SETTINGS);

        return Account::fromArray(HostAccountingGuard::run(fn (): array => $this->chart->create($tenantId, [
            'code' => $code,
            'name' => $name,
            'parent_id' => $parentId,
            'type' => $type,
            'subtype' => $subtype,
        ], $this->plugin)));
    }
}
