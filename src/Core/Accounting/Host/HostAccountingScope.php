<?php

declare(strict_types=1);

namespace Whity\Core\Accounting\Host;

use Whity\Sdk\Accounting\Accounts;
use Whity\Sdk\Accounting\Ledger;
use Whity\Sdk\Accounting\Periods;
use Whity\Sdk\Accounting\Reports;
use Whity\Sdk\Context\AccountingScope;
use Whity\Sdk\Context\ServiceUnavailable;
use Whity\Sdk\PluginInterface;

/**
 * `$ctx->accounting()` for one plugin (SDK 1.132, WHIT-1318). Periods and
 * reports are the container's host services; `ledger()` and `accounts()` are
 * the container's copies bound to this plugin, so its entries and accounts
 * carry this plugin's name. Nothing is kept per request: the services read
 * the workspace from the call each time.
 */
final class HostAccountingScope implements AccountingScope
{
    private ?Ledger $ledger = null;
    private ?Accounts $accounts = null;

    public function __construct(private readonly PluginInterface $plugin)
    {
    }

    public function accounts(): Accounts
    {
        if ($this->accounts === null) {
            $host = self::resolve(Accounts::class);
            if (!$host instanceof HostAccounts) {
                throw ServiceUnavailable::named(Accounts::class);
            }
            $this->accounts = $host->for($this->plugin);
        }

        return $this->accounts;
    }

    public function ledger(): Ledger
    {
        if ($this->ledger === null) {
            $host = self::resolve(Ledger::class);
            if (!$host instanceof HostLedger) {
                throw ServiceUnavailable::named(Ledger::class);
            }
            $this->ledger = $host->for($this->plugin);
        }

        return $this->ledger;
    }

    public function periods(): Periods
    {
        return self::resolve(Periods::class);
    }

    public function reports(): Reports
    {
        return self::resolve(Reports::class);
    }

    /**
     * @template T of object
     * @param class-string<T> $service
     * @return T
     */
    private static function resolve(string $service): object
    {
        try {
            $found = \Whity\app($service);
        } catch (\Throwable) {
            throw ServiceUnavailable::named($service);
        }
        if (!$found instanceof $service) {
            throw ServiceUnavailable::named($service);
        }

        return $found;
    }
}
