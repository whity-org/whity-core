<?php

declare(strict_types=1);

namespace Whity\Api;

use Closure;
use Whity\Auth\RoleChecker;
use Whity\Core\Dashboard\DashboardLayoutRepository;
use Whity\Core\PluginLoader;
use Whity\Core\Request;
use Whity\Core\Response;
use Whity\Core\Tenant\TenantContext;
use Whity\Sdk\Frontend\Blocks\BlockText;

/**
 * What the caller's apps add to their dashboard (SDK 1.115, WHIT-1177).
 *
 *   GET /api/me/dashboard          setup points and widgets the caller may see, in their order
 *   PUT /api/me/dashboard/layout   {"items": [{"key", "hidden", "size"?}]}, in order, for this person here
 *
 * Every point and widget is listed only when the caller holds its permission in
 * this workspace and its plugin is on here, so nobody learns about an app they
 * can't use. Neither carries data: a point carries the plugin route that says
 * whether it is done, a widget its blocks and the route they read. The browser
 * asks those routes, which apply their own permission and the person's scope,
 * and a slow or failing app holds up only its own point or widget.
 *
 * The layout covers home cards too ({@see HomeCardsApiHandler}): one order for
 * everything "From your apps". Widgets come after a plugin's card, plugin by
 * plugin, until the person arranges them.
 */
final class DashboardApiHandler
{
    /** @param Closure(): string $language the reader's language code */
    public function __construct(
        private readonly RoleChecker $roleChecker,
        private readonly PluginLoader $loader,
        private readonly DashboardLayoutRepository $layout,
        private readonly HomeCardsApiHandler $homeCards,
        private readonly Closure $language,
    ) {
    }

    /** @param array<string, string> $params */
    public function show(Request $request, array $params = []): Response
    {
        $caller = self::caller($request);
        if ($caller === null) {
            return Response::error('Unauthenticated', 401);
        }
        [$tenantId, $profileId] = $caller;
        $language = ($this->language)();

        $setup = [];
        foreach ($this->loader->getDashboards() as $dashboard) {
            $part = $dashboard['setup'];
            if ($part === null || !$this->may($profileId, (string) $part['permission'], $tenantId)) {
                continue;
            }
            $points = [];
            foreach ($part['points'] as $point) {
                $points[] = [
                    'id' => $point['id'],
                    'label' => self::text($point['label'], $language),
                    'why' => self::text($point['why'], $language),
                    'action' => self::text($point['action'], $language),
                    'href' => $point['step'] !== null
                        ? '/admin/setup/' . rawurlencode($dashboard['plugin']) . '?step=' . rawurlencode((string) $point['step'])
                        : '/admin/x/' . $point['screen'],
                    'icon' => $point['icon'],
                ];
            }
            $setup[] = ['plugin' => $dashboard['plugin'], 'status' => $part['status'], 'points' => $points];
        }

        $rows = $this->layout->rowsFor($tenantId, $profileId);
        $widgets = [];
        foreach ($this->visibleWidgets($tenantId, $profileId) as $widget) {
            $row = $rows[$widget['key']] ?? null;
            $size = $row['size'] ?? null;
            $widgets[] = [
                'key' => $widget['key'],
                'plugin' => $widget['plugin'],
                'kind' => $widget['kind'],
                'title' => self::text($widget['title'], $language),
                'description' => self::text($widget['description'], $language),
                'icon' => $widget['icon'],
                'href' => $widget['screen'] !== null ? '/admin/x/' . $widget['screen'] : null,
                'source' => $widget['source'],
                'sizes' => $widget['sizes'],
                'size' => is_string($size) && in_array($size, $widget['sizes'], true) ? $size : $widget['sizes'][0],
                'hidden' => (bool) ($row['hidden'] ?? false),
                'position' => $row['position'] ?? null,
                'blocks' => array_map(static fn (array $block): array => BlockText::resolve($block, $language), $widget['blocks']),
            ];
        }

        return Response::json(['data' => [
            'setup' => $setup,
            'widgets' => DashboardLayoutRepository::ordered($widgets, $rows),
        ]], 200);
    }

    /** @param array<string, string> $params */
    public function saveLayout(Request $request, array $params = []): Response
    {
        $caller = self::caller($request);
        if ($caller === null) {
            return Response::error('Unauthenticated', 401);
        }
        [$tenantId, $profileId] = $caller;

        $body = json_decode($request->getBody(), true);
        $items = is_array($body) ? ($body['items'] ?? null) : null;
        if (!is_array($items) || !array_is_list($items)) {
            return Response::error('items must be a list', 422);
        }

        // What the caller can see now: their cards by plugin name, their widgets
        // by plugin and id, each with the sizes it fits (a card has none).
        $sizes = [];
        foreach ($this->homeCards->visibleKeys($tenantId, $profileId) as $key) {
            $sizes[$key] = [];
        }
        foreach ($this->visibleWidgets($tenantId, $profileId) as $widget) {
            $sizes[$widget['key']] = $widget['sizes'];
        }

        $clean = [];
        foreach ($items as $i => $item) {
            $key = is_array($item) ? ($item['key'] ?? null) : null;
            if (!is_string($key) || !array_key_exists($key, $sizes)) {
                // An app the caller can't use answers as if it doesn't exist.
                return Response::error("items[{$i}].key: not on your dashboard", 422);
            }
            if (isset($clean[$key])) {
                return Response::error("items[{$i}].key: listed twice", 422);
            }
            $hidden = $item['hidden'] ?? false;
            $size = $item['size'] ?? null;
            if (!is_bool($hidden)) {
                return Response::error("items[{$i}].hidden: must be true or false", 422);
            }
            if ($size !== null && (!is_string($size) || !in_array($size, $sizes[$key], true))) {
                return Response::error("items[{$i}].size: not a size this item fits", 422);
            }
            $clean[$key] = ['key' => $key, 'hidden' => $hidden, 'size' => $size];
        }

        $this->layout->replace($tenantId, $profileId, array_keys($sizes), array_values($clean));

        return $this->show($request, $params);
    }

    /**
     * The widgets the caller may see here, in load order, keyed `plugin:id`.
     *
     * @return list<array{key: string, plugin: string, kind: string, title: mixed, description: mixed, permission: string, source: string|null, sizes: list<string>, blocks: list<array<string, mixed>>, screen: string|null, icon: string|null}>
     */
    private function visibleWidgets(int $tenantId, int $profileId): array
    {
        $out = [];
        foreach ($this->loader->getDashboards() as $dashboard) {
            foreach ($dashboard['widgets'] as $widget) {
                if (!$this->may($profileId, (string) $widget['permission'], $tenantId)) {
                    continue;
                }
                $out[] = ['key' => $dashboard['plugin'] . ':' . $widget['id'], 'plugin' => $dashboard['plugin']] + $widget;
            }
        }

        /** @var list<array{key: string, plugin: string, kind: string, title: mixed, description: mixed, permission: string, source: string|null, sizes: list<string>, blocks: list<array<string, mixed>>, screen: string|null, icon: string|null}> */
        return $out;
    }

    private function may(int $profileId, string $permission, int $tenantId): bool
    {
        return $this->roleChecker->hasPermissionForProfile($profileId, $permission, $tenantId);
    }

    /** `{en, ar}` in the reader's language, English when that one is missing. */
    private static function text(mixed $value, string $language): ?string
    {
        if (is_string($value)) {
            return $value;
        }
        if (!is_array($value)) {
            return null;
        }
        $text = $value[$language] ?? $value['en'] ?? null;

        return is_string($text) ? $text : null;
    }

    /** @return array{0: int, 1: int}|null */
    private static function caller(Request $request): ?array
    {
        $tenantId = TenantContext::getTenantId();
        $actor    = $request->user;
        $profile  = is_object($actor) && isset($actor->profile_id) && is_int($actor->profile_id) ? $actor->profile_id : null;

        return $tenantId === null || $profile === null ? null : [$tenantId, $profile];
    }
}
