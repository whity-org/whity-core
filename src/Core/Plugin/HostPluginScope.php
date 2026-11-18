<?php

declare(strict_types=1);

namespace Whity\Core\Plugin;

use Whity\Core\Tenant\TenantContext;
use Whity\Sdk\Audit\AuditTrail;
use Whity\Sdk\Audit\PluginAudit;
use Whity\Sdk\Context\PluginScope;
use Whity\Sdk\Context\ServiceUnavailable;
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

/** One plugin's view of the host ({@see HostPluginContext}, SDK 1.124). */
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
        return $this->store(PluginSettings::class, PluginSettingsStore::class);
    }

    public function secrets(): WorkspaceSecrets
    {
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
        return $this->store(PluginFiles::class, PluginFileStore::class);
    }

    public function documents(): PluginDocumentStore
    {
        return $this->store(PluginDocuments::class, PluginDocumentStore::class);
    }

    public function notify(): Notifier
    {
        return $this->store(PluginNotifier::class, Notifier::class);
    }

    public function jobs(): JobQueue
    {
        return $this->store(PluginJobQueue::class, JobQueue::class);
    }

    public function audit(): AuditTrail
    {
        return $this->store(PluginAudit::class, AuditTrail::class);
    }

    public function http(?AddressPolicy $policy = null): HttpClient
    {
        return new StreamHttpClient(addressPolicy: $policy);
    }

    public function themes(): WorkspaceTheme
    {
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
