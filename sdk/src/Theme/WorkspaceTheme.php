<?php

declare(strict_types=1);

namespace Whity\Sdk\Theme;

/**
 * The workspace's theme, read-only (SDK 1.126, WHIT-1225). Reached through
 * the plugin's context, so the workspace is the one in use (decision D2):
 *
 * ```php
 * $theme = \Whity\app(\Whity\Sdk\PluginContext::class)->for($this)->themes();
 * $primary = $theme->current()['light']['primary'] ?? null;
 * ```
 *
 * Use it to match what a plugin draws itself (a chart, a PDF cover, an
 * email) to the workspace's look. Values are the host's compiled tokens,
 * after the guard rails: colours as `#rrggbb`, only those that differ from
 * Whity's own. A plugin can't change the theme: a person chooses it.
 */
interface WorkspaceTheme
{
    /**
     * The theme in use, compiled.
     *
     * @return array{
     *   id: string,
     *   version: string,
     *   mainColor: ?string,
     *   light: array<string, string>,
     *   dark: array<string, string>,
     *   vars: array<string, string>,
     *   fonts: array{body: string, heading: string}
     * }
     */
    public function current(): array;

    /**
     * The themes this workspace can choose: Whity's own, plugins' and the
     * ones it imported. `source` is `whity`, `plugin`, `import` or `store`;
     * `plugin` names the plugin for a plugin's theme.
     *
     * @return list<array{id: string, version: string, name: array<string, string>, source: string, plugin: ?string, current: bool}>
     */
    public function installed(): array;
}
