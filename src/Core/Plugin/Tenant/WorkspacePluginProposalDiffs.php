<?php

declare(strict_types=1);

namespace Whity\Core\Plugin\Tenant;

use Whity\Mcp\Tools\ProposalDiffRegistry;
use Whity\Sdk\Manifest\InvalidManifestException;
use Whity\Sdk\PluginNamespace;

/**
 * What a proposal to publish or roll back a workspace plugin would do, for the person approving it
 * (WHIT-764). When an agent, such as Whity Builder, calls either operation, core files a proposal
 * instead of running it; this is that proposal's `diff`, shown on the Inbox's ActionProposal card.
 *
 * The diff is the same impact plan the routes compute ({@see ManifestImpact}): the version it
 * goes from and to, its worst effect (`level`) and each change with the records it touches, in
 * English and Arabic. For a publish it adds the version's own tests (`tests: {passed, failed}`),
 * run rolled back, so the person sees both before approving.
 *
 * Read only: nothing is written, and an argument that doesn't make a valid manifest gives no diff
 * (the proposal is still filed; publishing refuses it later).
 */
final class WorkspacePluginProposalDiffs
{
    /** The MCP tools of the publish and activate routes. */
    public const PUBLISH = 'post_api_workspace_plugins';
    public const ACTIVATE = 'post_api_workspace_plugins_plugin_versions_id_activate';

    public function __construct(
        private readonly TenantPluginRepository $plugins,
        private readonly TenantManifestPublisher $publisher,
        private readonly ManifestImpact $impact,
        private readonly ManifestTestRunner $tests,
    ) {
    }

    /**
     * @param array<string, mixed> $arguments The publish call's arguments.
     * @return array<string, mixed>|null
     */
    public function forPublish(array $arguments, int $tenantId): ?array
    {
        $manifest = $arguments['manifest'] ?? null;
        if (!is_array($manifest)) {
            return null;
        }
        try {
            $draft = $this->publisher->check($tenantId, $manifest);
        } catch (InvalidManifestException) {
            return null;
        }
        $plan = $this->impact->plan($tenantId, $this->plugins->liveManifest($tenantId, $draft->name()), $draft);
        $tests = $this->tests->run($tenantId, $draft);

        return $plan + ['tests' => ['passed' => $tests['passed'], 'failed' => $tests['failed']]];
    }

    /**
     * @param array<string, mixed> $arguments The activate call's arguments: plugin (slug) and id.
     * @return array<string, mixed>|null
     */
    public function forActivate(array $arguments, int $tenantId): ?array
    {
        $slug = $arguments['plugin'] ?? null;
        $id = $arguments['id'] ?? null;
        $plugin = is_string($slug) ? $this->plugins->pluginKey($tenantId, $slug) : null;
        $data = $plugin !== null && is_numeric($id) ? $this->plugins->manifest($tenantId, (int) $id) : null;
        if ($plugin === null || $data === null) {
            return null;
        }
        try {
            $target = $this->publisher->check($tenantId, $data);
        } catch (InvalidManifestException) {
            return null;
        }
        if (PluginNamespace::slug($target->name()) !== $slug) {
            return null;
        }

        return $this->impact->plan($tenantId, $this->plugins->liveManifest($tenantId, $plugin), $target);
    }

    /**
     * Register both on core's proposal-diff registry (WHIT-833), which files a proposal without a
     * diff when one can't be computed.
     */
    public function registerOn(ProposalDiffRegistry $registry): void
    {
        $registry->register(self::PUBLISH, $this->forPublish(...));
        $registry->register(self::ACTIVATE, $this->forActivate(...));
    }
}
