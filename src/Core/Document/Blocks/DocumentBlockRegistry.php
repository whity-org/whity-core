<?php

declare(strict_types=1);

namespace Whity\Core\Document\Blocks;

use Whity\Sdk\Manifest\DocumentBlockValidator;

/**
 * The blocks plugins add to the document editor (editor M7, E.17–E.20; SDK
 * 1.103), per workspace.
 *
 * Code plugins register theirs at load ({@see \Whity\Core\PluginLoader}); a
 * workspace's own plugins are read per request from their manifests, so a
 * plugin switched off in a workspace stops offering blocks there at once.
 *
 * Every block is a declaration drawn by Whity's renderer (register E.17,
 * Q13): its fields, a view and an optional print view in the screen
 * vocabulary's display blocks. `verified` comes from Whity's marks
 * (VERIFIED-PLUGINS-SECURITY.md), never from the plugin.
 *
 * A block in a document names its plugin and block; when that plugin is off
 * or gone, the editor shows the plugin-off placeholder and keeps the data
 * (E.19). {@see self::forTenant()} lists the workspace's switched-off plugins
 * so the placeholder can name one and offer Enable.
 */
final class DocumentBlockRegistry
{
    /** @var array<string, array{label: array{en: string, ar: string}, blocks: array<string, array<string, mixed>>}> */
    private array $code = [];

    /**
     * @param callable(int): list<array{plugin: string, label: array{en: string, ar: string}, verified: bool, blocks: array<string, array<string, mixed>>}> $workspace
     *        The workspace's switched-on plugins that declare blocks.
     * @param callable(int): list<array{plugin: string, label: array{en: string, ar: string}}> $off
     *        The workspace's installed plugins that are switched off.
     * @param callable(string): bool $codeVerified Whether a code plugin carries Whity's mark.
     */
    public function __construct(
        private readonly mixed $workspace,
        private readonly mixed $off,
        private readonly mixed $codeVerified,
    ) {
    }

    /**
     * A code plugin's blocks, replacing any it registered before.
     *
     * @param array{en: string, ar: string} $label
     * @param array<string, array<string, mixed>> $blocks block name => declaration
     */
    public function register(string $plugin, array $label, array $blocks): void
    {
        if ($blocks === []) {
            unset($this->code[$plugin]);

            return;
        }
        $this->code[$plugin] = ['label' => $label, 'blocks' => $blocks];
    }

    public function unregister(string $plugin): void
    {
        unset($this->code[$plugin]);
    }

    /**
     * The workspace's plugin blocks, one entry per plugin, and the plugins
     * installed there but switched off. A workspace plugin can't take a code
     * plugin's name, so the two never clash.
     *
     * @return array{plugins: list<array<string, mixed>>, off: list<array{plugin: string, label: array{en: string, ar: string}}>}
     */
    public function forTenant(int $tenantId): array
    {
        $plugins = [];
        foreach ($this->code as $plugin => $entry) {
            $plugins[] = self::entry($plugin, $entry['label'], ($this->codeVerified)($plugin), $entry['blocks']);
        }
        foreach (($this->workspace)($tenantId) as $row) {
            if (isset($this->code[$row['plugin']]) || $row['blocks'] === []) {
                continue;
            }
            $plugins[] = self::entry($row['plugin'], $row['label'], $row['verified'], $row['blocks']);
        }

        return ['plugins' => $plugins, 'off' => ($this->off)($tenantId)];
    }

    /**
     * One block's declaration as the workspace has it now, or null when its
     * plugin is off, gone or no longer declares it.
     *
     * @return array<string, mixed>|null
     */
    public function definition(int $tenantId, string $plugin, string $blockType): ?array
    {
        foreach ($this->forTenant($tenantId)['plugins'] as $entry) {
            if ($entry['plugin'] !== $plugin) {
                continue;
            }
            foreach ($entry['blocks'] as $block) {
                if ($block['blockType'] === $blockType) {
                    return $block;
                }
            }
        }

        return null;
    }

    /**
     * @param array{en: string, ar: string} $label
     * @param array<string, array<string, mixed>> $blocks
     * @return array<string, mixed>
     */
    private static function entry(string $plugin, array $label, bool $verified, array $blocks): array
    {
        $out = [];
        foreach ($blocks as $name => $block) {
            $prints = array_key_exists('print', $block);
            $out[] = [
                'name' => $plugin . '/' . $name,
                'blockType' => (string) $name,
                'label' => $block['label'],
                'description' => $block['description'] ?? null,
                'icon' => $block['icon'] ?? 'plugin',
                'keywords' => $block['keywords'] ?? [],
                'fields' => $block['fields'] ?? [],
                'view' => $block['view'],
                'print' => $prints ? $block['print'] : null,
                // E.18: without a print view, notes and wiki pages only.
                'allowedIn' => $block['allowedIn'] ?? ($prints ? DocumentBlockValidator::KINDS : ['note', 'wiki']),
            ];
        }

        return [
            'plugin' => $plugin,
            'label' => $label,
            'verified' => $verified,
            // No first-party plugin ships document blocks yet; Whity's own blocks are core.
            'firstParty' => false,
            'blocks' => $out,
        ];
    }
}
