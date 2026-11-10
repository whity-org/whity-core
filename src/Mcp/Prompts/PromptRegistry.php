<?php

declare(strict_types=1);

namespace Whity\Mcp\Prompts;

use Whity\Core\Container\HostWiredService;

/**
 * Registry of all available MCP prompts (WC-7755fc38).
 *
 * Core prompts are registered at boot by CorePrompts::register().
 * Plugin-contributed prompts will be registered via PluginMcpInterface
 * (WC-7abb732f). The registry is read at prompts/list and prompts/get
 * call time, so late-registered entries are naturally included.
 *
 * {@see HostWiredService}: an improvised, empty instance would list no prompts
 * and find none by name — the same answers a deployment with no prompts gives,
 * so the caller could never tell the two apart. An unregistered lookup throws
 * instead.
 */
final class PromptRegistry implements HostWiredService
{
    /** @var list<Prompt> */
    private array $prompts = [];

    /** @var (\Closure(string): bool)|null Whether a plugin's prompts are served now. */
    private ?\Closure $pluginGate = null;

    /**
     * Decide, at every list and get, whether a plugin's prompts are served (WHIT-1014: the
     * operator can require a live Verified mark). Asked per call rather than at collection, so
     * a revoked mark hides the prompts in every worker without a reload. Core prompts are never
     * gated. A gate that throws hides the plugin's prompts.
     *
     * @param (\Closure(string): bool)|null $gate
     */
    public function setPluginGate(?\Closure $gate): void
    {
        $this->pluginGate = $gate;
    }

    public function register(Prompt $prompt): void
    {
        $this->prompts[] = $prompt;
    }

    /**
     * Drop every registered prompt.
     *
     * The registry is a long-lived worker singleton that the prompts handlers
     * hold by reference, so it cannot be swapped for a fresh one when the plugin
     * registry reloads — and re-collecting into it without clearing would leave
     * an uninstalled plugin's prompts listed forever while duplicating the ones
     * that survived. Callers re-seed core prompts immediately afterwards; a
     * registry left empty lists nothing (#952).
     */
    public function reset(): void
    {
        $this->prompts = [];
    }

    /** @return list<Prompt> The prompts served now. */
    public function all(): array
    {
        if ($this->pluginGate === null) {
            return $this->prompts;
        }
        $allowed = [];

        return array_values(array_filter($this->prompts, function (Prompt $prompt) use (&$allowed): bool {
            if ($prompt->plugin === null) {
                return true;
            }

            return $allowed[$prompt->plugin] ??= $this->allows($prompt->plugin);
        }));
    }

    /** A prompt served now, by name. */
    public function find(string $name): ?Prompt
    {
        foreach ($this->prompts as $prompt) {
            if ($prompt->name === $name) {
                return $prompt->plugin === null || $this->pluginGate === null || $this->allows($prompt->plugin) ? $prompt : null;
            }
        }
        return null;
    }

    /** Whether a prompt of this name is registered, served or not: names stay first-come. */
    public function has(string $name): bool
    {
        foreach ($this->prompts as $prompt) {
            if ($prompt->name === $name) {
                return true;
            }
        }
        return false;
    }

    private function allows(string $plugin): bool
    {
        $gate = $this->pluginGate;
        if ($gate === null) {
            return true;
        }
        try {
            return $gate($plugin) === true;
        } catch (\Throwable) {
            return false;
        }
    }
}
