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
 * English and Arabic. It adds the version's own tests (`tests: {passed, failed}`), run rolled
 * back, so the person sees both before approving.
 *
 * WHIT-1042 (TplImpactPlan) adds what the card needs to read as a plan: `kind` (publish or
 * rollback), the plugin's `label` in both languages, and with the tests the first failing ones
 * by name (`tests.failures`) and when they ran (`tests.ranAt`). A rollback runs the tests of
 * the version it goes back to. The approvals handler refuses a plan whose tests failed and
 * asks for a tick on a destructive one ({@see self::planOf()}).
 *
 * Read only: nothing is written, and an argument that doesn't make a valid manifest gives no diff
 * (the proposal is still filed; publishing refuses it later).
 */
final class WorkspacePluginProposalDiffs
{
    /** The MCP tools of the publish and activate routes. */
    public const PUBLISH = 'post_api_workspace_plugins';
    public const ACTIVATE = 'post_api_workspace_plugins_plugin_versions_id_activate';

    public const KIND_PUBLISH = 'publish';
    public const KIND_ROLLBACK = 'rollback';

    /** Failing tests named on the card; the count covers the rest. */
    public const MAX_FAILURES = 5;

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
        return $this->plan($tenantId, $this->plugins->liveManifest($tenantId, $draft->name()), $draft, self::KIND_PUBLISH);
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

        return $this->plan($tenantId, $this->plugins->liveManifest($tenantId, $plugin), $target, self::KIND_ROLLBACK);
    }

    /**
     * The impact plan stored on a proposal of one of these tools, or null for anything else.
     *
     * @return array{level: string, tests?: array{passed?: int, failed?: int}}|null
     */
    public static function planOf(mixed $tool, mixed $diff): ?array
    {
        if (($tool !== self::PUBLISH && $tool !== self::ACTIVATE) || !is_array($diff)
            || !is_string($diff['level'] ?? null) || !is_array($diff['changes'] ?? null)) {
            return null;
        }
        $tests = is_array($diff['tests'] ?? null) ? $diff['tests'] : [];

        return [
            'level' => $diff['level'],
            'tests' => ['passed' => (int) ($tests['passed'] ?? 0), 'failed' => (int) ($tests['failed'] ?? 0)],
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function plan(int $tenantId, ?\Whity\Sdk\Manifest\PluginManifest $live, \Whity\Sdk\Manifest\PluginManifest $target, string $kind): array
    {
        $plan = $this->impact->plan($tenantId, $live, $target);
        $tests = $this->tests->run($tenantId, $target);
        $failures = [];
        foreach ($tests['results'] as $result) {
            if (($result['passed'] ?? false) !== true && count($failures) < self::MAX_FAILURES) {
                $failures[] = self::bilingual($result['label'] ?? '');
            }
        }

        return ['kind' => $kind, 'label' => $target->label()] + $plan + ['tests' => [
            'passed' => $tests['passed'],
            'failed' => $tests['failed'],
            'failures' => $failures,
            'ranAt' => gmdate('Y-m-d\TH:i:s\Z'),
        ]];
    }

    /** @return array{en: string, ar: string} */
    private static function bilingual(mixed $label): array
    {
        if (is_array($label)) {
            $en = is_string($label['en'] ?? null) ? $label['en'] : '';
            return ['en' => $en, 'ar' => is_string($label['ar'] ?? null) && $label['ar'] !== '' ? $label['ar'] : $en];
        }
        $text = is_string($label) ? $label : '';
        return ['en' => $text, 'ar' => $text];
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
