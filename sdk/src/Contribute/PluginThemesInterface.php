<?php

declare(strict_types=1);

namespace Whity\Sdk\Contribute;

/**
 * Ship themes with a plugin (SDK 1.126, WHIT-1225, decision TH.13).
 *
 * A theme is data, never CSS: each one is a theme file in the format of
 * `schemas/theme/v1.json` (also `@whity/tokens/theme.schema.json`), decoded
 * to an array. The host checks every theme on its own when the plugin loads
 * and drops one that fails, with the reasons in the log; the rest are kept.
 *
 * The themes appear in Settings › Branding › Theme, marked "From <plugin>",
 * in every workspace where the plugin is on. A plugin never applies a theme
 * itself: a person who may change the workspace's settings chooses it, and
 * applying it is free. The host's guard rails (contrast, minimum sizes,
 * Arabic and right-to-left, status colours) run on the result whatever the
 * theme says.
 *
 * Rules the host enforces:
 *  - `id` is `vendor.name` in lowercase; `whity.` is Whity's own and refused.
 *  - An id offered by two plugins is kept from the first loaded.
 *  - Fonts come from the allowed list only; no URLs, images or CSS anywhere.
 *
 * ```php
 * public function getThemes(): array
 * {
 *     return [json_decode((string) file_get_contents(__DIR__ . '/themes/harbour.json'), true)];
 * }
 * ```
 *
 * Replaces {@see \Whity\Sdk\PluginThemeInterface}, which overrides colours
 * through a route and is deprecated.
 */
interface PluginThemesInterface
{
    /**
     * @return list<array<string, mixed>> Theme files, decoded.
     */
    public function getThemes(): array;
}
