<?php

declare(strict_types=1);

namespace Whity\Core;

use Whity\Core\Hooks\HookManager;
use Whity\Sdk\I18n\LocalizedText;

/**
 * Descriptor-derived navigation (WC-169).
 *
 * Plugins that declare a frontend feature ({@see \Whity\Sdk\PluginFrontendInterface})
 * get a menu entry automatically: this bridge subscribes to the existing
 * `navigation.register` hook and appends one item per validated descriptor,
 * pointing at the host's dynamic plugin-screen route (`/admin/x/{id}`).
 *
 * Design constraints:
 *  - `navigation.register` remains the menu primitive — plugins can still
 *    register bespoke links directly; the bridge only APPENDS, never replaces
 *    or filters existing items.
 *  - Features are read from the loader at DISPATCH time, so a plugin disabled
 *    at runtime drops out of the menu on the next request without rebuilding
 *    the hook chain.
 *  - The item carries the descriptor's `requiredPermission` exactly like the
 *    permission-gated core items (delegations, relations): a permission-aware
 *    client may hide it, and the screen behind it is enforced server-side
 *    regardless (the features endpoint filters per-caller; the data API
 *    enforces route-level RBAC).
 */
final class PluginNavigationBridge
{
    /**
     * Icon used when a descriptor declares none — plugins stay recognizable
     * in the menu without forcing every author to pick an icon.
     */
    private const FALLBACK_ICON = 'puzzle';

    private function __construct()
    {
    }

    /**
     * Subscribe the bridge to `navigation.register` on the given hook manager.
     *
     * @param HookManager  $hooks  The host hook manager.
     * @param PluginLoader $loader The loader whose validated descriptors drive the items.
     * @param (\Closure(): string)|null $language The caller's language, for a
     *        label written as {en, ar} (SDK 1.101); English when absent.
     * @return void
     */
    public static function subscribe(HookManager $hooks, PluginLoader $loader, ?\Closure $language = null): void
    {
        $hooks->listen(
            'navigation.register',
            /**
             * @param array<string, mixed> $data
             * @return array<string, mixed>
             */
            static function (array $data) use ($loader, $language): array {
                $items = $data['items'] ?? [];
                $code = $language !== null ? $language() : 'en';

                foreach ($loader->getFrontendFeatures() as $feature) {
                    $items[] = self::toNavItem($feature, $code);
                }

                return ['items' => $items];
            }
        );
    }

    /**
     * Map one validated, normalized frontend feature descriptor (as emitted by
     * {@see PluginLoader::getFrontendFeatures()}) to a navigation item.
     *
     * The nav id is prefixed `plugin-` so descriptor ids can never collide
     * with core navigation ids.
     *
     * @param array<string, mixed> $feature  A normalized descriptor.
     * @param string               $language The reader's language, for a label
     *                                       written as {en, ar} (SDK 1.101).
     * @return array<string, mixed> The navigation item.
     */
    public static function toNavItem(array $feature, string $language = 'en'): array
    {
        $icon = $feature['icon'] ?? null;
        $label = $feature['label'] ?? '';
        if (is_array($label)) {
            $label = LocalizedText::resolve($label, $language) ?? '';
        }

        return [
            'id' => 'plugin-' . (string) $feature['id'],
            'label' => is_string($label) ? $label : '',
            'href' => '/admin/x/' . (string) $feature['id'],
            'icon' => is_string($icon) && $icon !== '' ? $icon : self::FALLBACK_ICON,
            'group' => (string) ($feature['group'] ?? 'plugins'),
            'order' => (int) ($feature['order'] ?? 100),
            'requiredPermission' => (string) $feature['requiredPermission'],
        ];
    }
}
