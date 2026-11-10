<?php

declare(strict_types=1);

namespace Whity\Core\Plugin;

use Whity\Core\Settings\PluginSettingsRegistry;
use Whity\Core\Settings\SettingDefinition;
use Whity\Core\Settings\SettingsService;
use Whity\Core\Settings\SettingsValidationException;
use Whity\Core\Tenant\TenantContext;
use Whity\Sdk\Settings\PluginSettingsStore;

/**
 * One plugin's settings (SDK 1.88, WHIT-1016). See {@see HostPluginSettings}.
 *
 * The workspace is read from {@see TenantContext} on every call and never kept,
 * so a store held across requests by a worker can't serve the wrong workspace.
 * A key resolves only when the catalogue says this plugin declared it, which is
 * what keeps core's and other plugins' keys out of reach.
 */
final class HostPluginSettingsStore implements PluginSettingsStore
{
    public function __construct(
        private readonly SettingsService $settings,
        private readonly string $plugin,
    ) {
    }

    public function get(string $key): string|int|bool
    {
        $canonical = $this->canonical($key);
        return $this->typed($canonical, $this->settings->effective($this->workspace())[$canonical]);
    }

    public function all(): array
    {
        $values = [];
        $prefixLength = strlen(PluginSettingsRegistry::canonicalKey($this->plugin, 'x')) - 1;
        foreach ($this->settings->effective($this->workspace()) as $key => $value) {
            if ($this->settings->catalog()->sourceOf($key) === $this->plugin) {
                $values[substr($key, $prefixLength)] = $this->typed($key, $value);
            }
        }
        return $values;
    }

    public function set(string $key, string|int|bool $value): void
    {
        $this->write($key, match (true) {
            is_bool($value) => $value ? 'true' : 'false',
            is_int($value)  => (string) $value,
            default         => $value,
        });
    }

    public function reset(string $key): void
    {
        $this->write($key, null);
    }

    private function write(string $key, ?string $value): void
    {
        $canonical = $this->canonical($key);
        $workspace = $this->workspace();
        if ($this->settings->catalog()->isGlobalOnly($canonical)) {
            throw new \InvalidArgumentException("'{$key}' is set for the whole deployment, not per workspace.");
        }
        try {
            $this->settings->setTenant($workspace, $canonical, $value);
        } catch (SettingsValidationException $e) {
            throw new \InvalidArgumentException($e->getMessage(), 0, $e);
        }
    }

    private function canonical(string $key): string
    {
        $canonical = PluginSettingsRegistry::canonicalKey($this->plugin, $key);
        if ($this->settings->catalog()->sourceOf($canonical) !== $this->plugin) {
            throw new \InvalidArgumentException("This plugin did not declare a setting '{$key}'.");
        }
        return $canonical;
    }

    private function workspace(): int
    {
        $workspace = TenantContext::getTenantId();
        if ($workspace === null || $workspace <= SettingsService::SYSTEM_TENANT_ID) {
            throw new \RuntimeException('Plugin settings need a workspace, and this call has none.');
        }
        return $workspace;
    }

    private function typed(string $key, string $value): string|int|bool
    {
        return match ($this->settings->catalog()->typeFor($key)) {
            SettingDefinition::TYPE_BOOL => $value === 'true',
            SettingDefinition::TYPE_INT  => (int) $value,
            default                      => $value,
        };
    }
}
