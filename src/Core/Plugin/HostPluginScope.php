<?php

declare(strict_types=1);

namespace Whity\Core\Plugin;

use Whity\Core\Tenant\TenantContext;
use Whity\Sdk\Audit\AuditTrail;
use Whity\Sdk\Audit\PluginAudit;
use Whity\Core\Accounting\Host\HostAccountingScope;
use Whity\Core\Inventory\Host\HostInventoryScope;
use Whity\Sdk\Context\AccountingScope;
use Whity\Sdk\Context\InventoryScope;
use Whity\Sdk\Context\PluginScope;
use Whity\Sdk\Context\Service;
use Whity\Sdk\Context\ServiceNotDeclared;
use Whity\Sdk\Context\ServiceUnavailable;
use Whity\Sdk\Database\WorkspaceTables;
use Whity\Sdk\Log\ErrorReporter;
use Whity\Sdk\Log\PluginLog;
use Whity\Sdk\Documents\PluginDocuments;
use Whity\Sdk\Documents\PluginDocumentStore;
use Whity\Sdk\Http\Outbound\AddressPolicy;
use Whity\Sdk\Http\Outbound\HttpClient;
use Whity\Sdk\Http\Outbound\StreamHttpClient;
use Whity\Sdk\Jobs\JobQueue;
use Whity\Sdk\Jobs\PluginJobQueue;
use Whity\Sdk\Notification\Notifier;
use Whity\Sdk\Notification\PluginNotifier;
use Whity\Sdk\PluginInterface;
use Whity\Sdk\Secrets\PluginSecrets;
use Whity\Sdk\Secrets\WorkspaceSecrets;
use Whity\Sdk\Settings\PluginSettings;
use Whity\Sdk\Settings\PluginSettingsStore;
use Whity\Sdk\Storage\PluginFiles;
use Whity\Sdk\Storage\PluginFileStore;
use Whity\Sdk\Theme\WorkspaceTheme;

/**
 * One plugin's view of the host ({@see HostPluginContext}, SDK 1.124).
 *
 * A plugin that declares its services ({@see PluginCapabilities}, SDK 1.151,
 * #1693) gets only those; asking for another throws {@see ServiceNotDeclared}.
 * One that declares nothing keeps every service except `database()` until 2.0.
 */
final class HostPluginScope implements PluginScope
{
    /** @var array<class-string, object> */
    private array $stores = [];

    public function __construct(private readonly PluginInterface $plugin)
    {
    }

    public function pluginName(): string
    {
        return $this->plugin->getName();
    }

    public function workspaceId(): ?int
    {
        $workspace = TenantContext::getTenantId();

        return $workspace !== null && $workspace > 0 ? $workspace : null;
    }

    public function settings(): PluginSettingsStore
    {
        $this->allow(Service::SETTINGS);
        return $this->store(PluginSettings::class, PluginSettingsStore::class);
    }

    public function secrets(): WorkspaceSecrets
    {
        $this->allow(Service::SECRETS);
        if (!isset($this->stores[WorkspaceSecrets::class])) {
            $factory = $this->factory(PluginSecrets::class);
            $this->stores[WorkspaceSecrets::class] = new HostWorkspaceSecrets($factory->for($this->plugin), $this->workspaceId(...));
        }
        $secrets = $this->stores[WorkspaceSecrets::class];
        assert($secrets instanceof WorkspaceSecrets);

        return $secrets;
    }

    public function files(): PluginFileStore
    {
        $this->allow(Service::FILES);
        return $this->store(PluginFiles::class, PluginFileStore::class);
    }

    public function documents(): PluginDocumentStore
    {
        $this->allow(Service::DOCUMENTS);
        return $this->store(PluginDocuments::class, PluginDocumentStore::class);
    }

    public function notify(): Notifier
    {
        $this->allow(Service::NOTIFY);
        return $this->store(PluginNotifier::class, Notifier::class);
    }

    public function jobs(): JobQueue
    {
        $this->allow(Service::JOBS);
        return $this->store(PluginJobQueue::class, JobQueue::class);
    }

    public function audit(): AuditTrail
    {
        $this->allow(Service::AUDIT);
        return $this->store(PluginAudit::class, AuditTrail::class);
    }

    public function http(?AddressPolicy $policy = null): HttpClient
    {
        $this->allow(Service::HTTP);
        return new StreamHttpClient(addressPolicy: $policy);
    }

    public function themes(): WorkspaceTheme
    {
        $this->allow(Service::THEMES);
        // The service reads the workspace from the request's context (D2).
        if (!isset($this->stores[WorkspaceTheme::class])) {
            $this->stores[WorkspaceTheme::class] = $this->factory(WorkspaceTheme::class);
        }
        $service = $this->stores[WorkspaceTheme::class];
        if (!$service instanceof WorkspaceTheme) {
            throw ServiceUnavailable::named(WorkspaceTheme::class);
        }

        return $service;
    }

    /** Core's inventory for this plugin; its stock is bound to this plugin (SDK 1.127, WHIT-1232). */
    public function inventory(): InventoryScope
    {
        $this->allow(Service::INVENTORY);
        if (!isset($this->stores[InventoryScope::class])) {
            $this->stores[InventoryScope::class] = new HostInventoryScope($this->plugin);
        }
        $scope = $this->stores[InventoryScope::class];
        assert($scope instanceof InventoryScope);

        return $scope;
    }

    /** Core's books for this plugin; its ledger and accounts are bound to this plugin (SDK 1.154, WHIT-1318). */
    public function accounting(): AccountingScope
    {
        $this->allow(Service::ACCOUNTING);
        if (!isset($this->stores[AccountingScope::class])) {
            $this->stores[AccountingScope::class] = new HostAccountingScope($this->plugin);
        }
        $scope = $this->stores[AccountingScope::class];
        assert($scope instanceof AccountingScope);

        return $scope;
    }

    /** This plugin's own tables in this workspace (SDK 1.151, #1693). */
    public function database(): WorkspaceTables
    {
        $declared = PluginCapabilities::forPlugin($this->plugin);
        if ($declared === null || !in_array(Service::DATABASE, $declared['services'], true)) {
            throw ServiceNotDeclared::named($this->plugin->getName(), Service::DATABASE);
        }
        if (!isset($this->stores[WorkspaceTables::class])) {
            $this->stores[WorkspaceTables::class] = new HostWorkspaceTables(
                $this->plugin->getName(),
                $declared['tables'],
                function (): \PDO {
                    $database = $this->factory(\Whity\Database\Database::class);

                    return $database->getPdo();
                },
                $this->workspaceId(...),
            );
        }
        $tables = $this->stores[WorkspaceTables::class];
        assert($tables instanceof WorkspaceTables);

        return $tables;
    }

    /** This plugin's scrubbed log (SDK 1.152, #1686); needs no declaration. */
    public function log(): PluginLog
    {
        return $this->pluginLog();
    }

    /** Report a caught error, scrubbed and tagged (SDK 1.152, #1686); needs no declaration. */
    public function errors(): ErrorReporter
    {
        return $this->pluginLog();
    }

    private function pluginLog(): HostPluginLog
    {
        if (!isset($this->stores[HostPluginLog::class])) {
            $logger = $GLOBALS['whity_services'][\Psr\Log\LoggerInterface::class] ?? null;
            $tracker = $GLOBALS['whity_services'][\Whity\Core\Observability\ErrorTracker::class] ?? null;
            $this->stores[HostPluginLog::class] = new HostPluginLog(
                $this->plugin->getName(),
                $logger instanceof \Psr\Log\LoggerInterface ? $logger : new \Whity\Core\Log\ErrorLogLogger(),
                $tracker instanceof \Whity\Core\Observability\ErrorTracker ? $tracker : null,
                $this->workspaceId(...),
            );
        }
        $log = $this->stores[HostPluginLog::class];
        assert($log instanceof HostPluginLog);

        return $log;
    }

    /** Refuse a service a declaring plugin left out. */
    private function allow(string $service): void
    {
        $declared = PluginCapabilities::forPlugin($this->plugin);
        if ($declared !== null && !in_array($service, $declared['services'], true)) {
            throw ServiceNotDeclared::named($this->plugin->getName(), $service);
        }
    }

    /**
     * @template T of object
     * @param class-string<PluginSettings|PluginFiles|PluginDocuments|PluginNotifier|PluginJobQueue|PluginAudit> $factory
     * @param class-string<T> $store
     * @return T
     */
    private function store(string $factory, string $store): object
    {
        if (!isset($this->stores[$store])) {
            $this->stores[$store] = $this->factory($factory)->for($this->plugin);
        }
        $resolved = $this->stores[$store];
        if (!$resolved instanceof $store) {
            throw ServiceUnavailable::named($store);
        }

        return $resolved;
    }

    /**
     * @template F of object
     * @param class-string<F> $factory
     * @return F
     */
    private function factory(string $factory): object
    {
        try {
            $service = \Whity\app($factory);
        } catch (\Throwable) {
            throw ServiceUnavailable::named($factory);
        }
        if (!$service instanceof $factory) {
            throw ServiceUnavailable::named($factory);
        }

        return $service;
    }
}
