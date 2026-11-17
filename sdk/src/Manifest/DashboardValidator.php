<?php

declare(strict_types=1);

namespace Whity\Sdk\Manifest;

use Whity\Sdk\Frontend\Blocks\BlockContract;
use Whity\Sdk\Frontend\Blocks\BlockValidator;

/**
 * The shape of {@see \Whity\Sdk\PluginDashboardInterface::getDashboard()}
 * (SDK 1.115, WHIT-1177): setup points for "Finish setting up" and dashboard
 * widgets.
 *
 * ```php
 * return [
 *     'setup' => [
 *         'permission' => 'salons:manage',
 *         'status' => '/api/salons/setup-points',
 *         'points' => [[
 *             'id' => 'chairs',
 *             'label' => ['en' => 'Add your chairs', 'ar' => 'أضف كراسي الصالون'],
 *             'why' => ['en' => 'Bookings need a chair to sit in', 'ar' => 'الحجوزات تحتاج إلى كرسي'],
 *             'step' => 'chairs',
 *         ]],
 *     ],
 *     'widgets' => [[
 *         'id' => 'todays-bookings',
 *         'kind' => 'list',
 *         'title' => ['en' => "Today's bookings", 'ar' => 'حجوزات اليوم'],
 *         'permission' => 'salons:read',
 *         'source' => '/api/salons/today',
 *         'sizes' => ['medium', 'large'],
 *         'blocks' => [['type' => 'dataList', 'source' => '/api/salons/today', 'itemField' => 'line',
 *             'emptyText' => ['en' => 'No bookings today', 'ar' => 'لا حجوزات اليوم']]],
 *         'screen' => 'salons-bookings',
 *     ]],
 * ];
 * ```
 *
 * This checks what the declaration says about itself. The host also checks what
 * only it knows: that `status` and `source` are GET routes this plugin registered
 * under the same permission, that `step` is a step of its guided setup and
 * `screen` one of its screens, and that every endpoint in the blocks is its own.
 */
final class DashboardValidator
{
    /** The most setup points one plugin may add. */
    public const MAX_POINTS = 6;

    /** The most widgets one plugin may declare. */
    public const MAX_WIDGETS = 8;

    public const KINDS = ['stat', 'list', 'chart', 'actions'];

    public const SIZES = ['small', 'medium', 'large'];

    /** The widths a widget fits when it names none, first the one it starts at. */
    public const DEFAULT_SIZES = [
        'stat' => ['small', 'medium'],
        'list' => ['medium', 'large'],
        'chart' => ['medium', 'large'],
        'actions' => ['small', 'medium'],
    ];

    /** The blocks each kind is built from. Anything else in the tree is refused. */
    public const BLOCKS_BY_KIND = [
        'stat' => ['dataStat', 'stat', 'row', 'grid', 'text', 'badge'],
        'list' => ['dataList', 'dataTable', 'text', 'button'],
        'chart' => ['chart', 'text'],
        'actions' => ['button', 'actionButton', 'row', 'text'],
    ];

    /** At least one of these makes the widget what its kind says. */
    public const MAIN_BLOCKS = [
        'stat' => ['dataStat'],
        'list' => ['dataList', 'dataTable'],
        'chart' => ['chart'],
        'actions' => ['button', 'actionButton'],
    ];

    private const ID_PATTERN = '/^[a-z][a-z0-9-]{0,40}$/';
    private const PERMISSION_PATTERN = '/^[a-z][a-z0-9_]*(:[a-z][a-z0-9_]*)+$/';
    private const ROUTE_PATTERN = '#^/api/[A-Za-z0-9_\-/]+$#';
    private const ICON_PATTERN = '/^[a-z0-9-]{1,64}$/';

    private const TEXT_MAX = [
        'label' => 80,
        'why' => 120,
        'action' => 24,
        'title' => 60,
        'description' => 160,
    ];

    private const TOP_KEYS = ['setup', 'widgets'];
    private const SETUP_KEYS = ['permission', 'status', 'points'];
    private const POINT_KEYS = ['id', 'label', 'why', 'action', 'step', 'screen', 'icon'];
    private const WIDGET_KEYS = ['id', 'kind', 'title', 'description', 'permission', 'source', 'sizes', 'blocks', 'screen', 'icon'];

    private function __construct()
    {
    }

    /**
     * Everything wrong with a whole declaration.
     *
     * @return list<string> each naming its path; empty when valid
     */
    public static function errors(mixed $dashboard): array
    {
        if (!is_array($dashboard) || ($dashboard !== [] && array_is_list($dashboard))) {
            return ['dashboard: must be an object'];
        }

        $errors = [];
        foreach (array_keys($dashboard) as $key) {
            if (!in_array($key, self::TOP_KEYS, true)) {
                $errors[] = "dashboard.{$key}: unknown key";
            }
        }
        if (array_key_exists('setup', $dashboard)) {
            array_push($errors, ...self::setupErrors($dashboard['setup']));
        }
        if (array_key_exists('widgets', $dashboard)) {
            $widgets = $dashboard['widgets'];
            if (!is_array($widgets) || !array_is_list($widgets)) {
                $errors[] = 'dashboard.widgets: must be a list';
            } else {
                if (count($widgets) > self::MAX_WIDGETS) {
                    $errors[] = 'dashboard.widgets: at most ' . self::MAX_WIDGETS;
                }
                $seen = [];
                foreach ($widgets as $i => $widget) {
                    array_push($errors, ...self::widgetErrors($widget, "dashboard.widgets[{$i}]"));
                    $id = is_array($widget) ? ($widget['id'] ?? null) : null;
                    if (is_string($id)) {
                        if (isset($seen[$id])) {
                            $errors[] = "dashboard.widgets[{$i}].id: {$id} is used twice";
                        }
                        $seen[$id] = true;
                    }
                }
            }
        }

        return $errors;
    }

    /**
     * The setup part: who sees the points, the status route, and the points.
     *
     * @return list<string>
     */
    public static function setupErrors(mixed $setup): array
    {
        if (!is_array($setup) || array_is_list($setup)) {
            return ['dashboard.setup: must be an object'];
        }

        $errors = self::unknownKeys($setup, self::SETUP_KEYS, 'dashboard.setup');
        if (!self::isPermission($setup['permission'] ?? null)) {
            $errors[] = 'dashboard.setup.permission: required, a permission slug';
        }
        if (!self::isRoute($setup['status'] ?? null)) {
            $errors[] = 'dashboard.setup.status: required, a path under /api/ with no parameters';
        }

        $points = $setup['points'] ?? null;
        if (!is_array($points) || !array_is_list($points) || $points === []) {
            $errors[] = 'dashboard.setup.points: required, a list of at least one point';

            return $errors;
        }
        if (count($points) > self::MAX_POINTS) {
            $errors[] = 'dashboard.setup.points: at most ' . self::MAX_POINTS;
        }
        $seen = [];
        foreach ($points as $i => $point) {
            array_push($errors, ...self::pointErrors($point, "dashboard.setup.points[{$i}]"));
            $id = is_array($point) ? ($point['id'] ?? null) : null;
            if (is_string($id)) {
                if (isset($seen[$id])) {
                    $errors[] = "dashboard.setup.points[{$i}].id: {$id} is used twice";
                }
                $seen[$id] = true;
            }
        }

        return $errors;
    }

    /**
     * One setup point.
     *
     * @return list<string>
     */
    public static function pointErrors(mixed $point, string $path = 'point'): array
    {
        if (!is_array($point) || array_is_list($point)) {
            return ["{$path}: must be an object"];
        }

        $errors = self::unknownKeys($point, self::POINT_KEYS, $path);
        if (!is_string($point['id'] ?? null) || preg_match(self::ID_PATTERN, $point['id']) !== 1) {
            $errors[] = "{$path}.id: required, lowercase letters, digits and dashes";
        }
        array_push($errors, ...self::textErrors($point, 'label', true, $path));
        array_push($errors, ...self::textErrors($point, 'why', false, $path));
        array_push($errors, ...self::textErrors($point, 'action', false, $path));

        $step = $point['step'] ?? null;
        $screen = $point['screen'] ?? null;
        if (($step === null) === ($screen === null)) {
            $errors[] = "{$path}: exactly one of step and screen is required";
        }
        if ($step !== null && (!is_string($step) || preg_match(self::ID_PATTERN, $step) !== 1)) {
            $errors[] = "{$path}.step: must name a step of this plugin's guided setup";
        }
        if ($screen !== null && (!is_string($screen) || $screen === '')) {
            $errors[] = "{$path}.screen: must name a screen of this plugin";
        }
        array_push($errors, ...self::iconErrors($point, $path));

        return $errors;
    }

    /**
     * One widget, its blocks included.
     *
     * @return list<string>
     */
    public static function widgetErrors(mixed $widget, string $path = 'widget'): array
    {
        if (!is_array($widget) || array_is_list($widget)) {
            return ["{$path}: must be an object"];
        }

        $errors = self::unknownKeys($widget, self::WIDGET_KEYS, $path);
        if (!is_string($widget['id'] ?? null) || preg_match(self::ID_PATTERN, $widget['id']) !== 1) {
            $errors[] = "{$path}.id: required, lowercase letters, digits and dashes";
        }
        $kind = $widget['kind'] ?? null;
        if (!is_string($kind) || !in_array($kind, self::KINDS, true)) {
            $errors[] = "{$path}.kind: required, one of " . implode(', ', self::KINDS);
            $kind = null;
        }
        array_push($errors, ...self::textErrors($widget, 'title', true, $path));
        array_push($errors, ...self::textErrors($widget, 'description', false, $path));
        if (!self::isPermission($widget['permission'] ?? null)) {
            $errors[] = "{$path}.permission: required, a permission slug";
        }

        $source = $widget['source'] ?? null;
        if ($source !== null && !self::isRoute($source)) {
            $errors[] = "{$path}.source: a path under /api/ with no parameters";
            $source = null;
        } elseif ($source === null && $kind !== null && $kind !== 'actions') {
            $errors[] = "{$path}.source: required for a {$kind} widget";
        }

        if (array_key_exists('sizes', $widget)) {
            $sizes = $widget['sizes'];
            if (!is_array($sizes) || !array_is_list($sizes) || $sizes === []
                || array_diff($sizes, self::SIZES) !== [] || count(array_unique($sizes)) !== count($sizes)) {
                $errors[] = "{$path}.sizes: a list of " . implode(', ', self::SIZES) . ', each once';
            }
        }

        if (isset($widget['screen']) && (!is_string($widget['screen']) || $widget['screen'] === '')) {
            $errors[] = "{$path}.screen: must name a screen of this plugin";
        }
        array_push($errors, ...self::iconErrors($widget, $path));

        $blocks = $widget['blocks'] ?? null;
        if (!is_array($blocks) || !array_is_list($blocks) || $blocks === []) {
            $errors[] = "{$path}.blocks: required, a list of blocks";

            return $errors;
        }
        foreach (BlockValidator::validate($blocks)['errors'] as $error) {
            $errors[] = "{$path}.{$error}";
        }
        if ($kind !== null) {
            array_push($errors, ...self::kindErrors($blocks, $kind, is_string($source) ? $source : null, $path));
        }

        return $errors;
    }

    /**
     * The sizes a valid widget fits, first the one it starts at.
     *
     * @param array<string, mixed> $widget
     * @return list<string>
     */
    public static function sizesOf(array $widget): array
    {
        $sizes = $widget['sizes'] ?? null;
        if (is_array($sizes) && $sizes !== []) {
            return array_values(array_map('strval', $sizes));
        }

        return self::DEFAULT_SIZES[(string) ($widget['kind'] ?? 'stat')] ?? ['medium'];
    }

    /**
     * Every block in the tree is one its kind allows, every data-bound block
     * reads the widget's own source, and the kind's main block is there.
     *
     * @param list<mixed> $blocks
     * @return list<string>
     */
    private static function kindErrors(array $blocks, string $kind, ?string $source, string $path): array
    {
        $errors = [];
        $allowed = self::BLOCKS_BY_KIND[$kind];
        $hasMain = false;
        $walk = static function (array $nodes, string $at) use (&$walk, &$errors, &$hasMain, $allowed, $kind, $source): void {
            foreach ($nodes as $i => $node) {
                if (!is_array($node) || !is_string($node['type'] ?? null)) {
                    continue;
                }
                $type = $node['type'];
                $here = "{$at}[{$i}]";
                if (!in_array($type, $allowed, true)) {
                    $errors[] = "{$here}: a {$kind} widget is built from " . implode(', ', $allowed) . ", not {$type}";
                    continue;
                }
                if (in_array($type, self::MAIN_BLOCKS[$kind], true)) {
                    $hasMain = true;
                }
                $rule = BlockContract::rulesFor($type);
                if ($rule !== null && ($rule['props']['source']['type'] ?? null) === 'apiPath' && ($node['source'] ?? null) !== $source) {
                    $errors[] = "{$here}.source: must be the widget's own source";
                }
                foreach (BlockContract::childSlots($type) as $slot) {
                    if (is_array($node[$slot] ?? null)) {
                        $walk($node[$slot], "{$here}.{$slot}");
                    }
                }
            }
        };
        $walk($blocks, "{$path}.blocks");

        if (!$hasMain) {
            $errors[] = "{$path}.blocks: a {$kind} widget needs one of " . implode(', ', self::MAIN_BLOCKS[$kind]);
        }

        return $errors;
    }

    /**
     * @param array<mixed> $value
     * @param list<string> $known
     * @return list<string>
     */
    private static function unknownKeys(array $value, array $known, string $path): array
    {
        $errors = [];
        foreach (array_keys($value) as $key) {
            if (!in_array($key, $known, true)) {
                $errors[] = "{$path}.{$key}: unknown key";
            }
        }

        return $errors;
    }

    /**
     * @param array<mixed> $value
     * @return list<string>
     */
    private static function textErrors(array $value, string $key, bool $required, string $path): array
    {
        if (!$required && !array_key_exists($key, $value)) {
            return [];
        }
        $max = self::TEXT_MAX[$key];
        $text = $value[$key] ?? null;
        $errors = [];
        foreach (['en', 'ar'] as $language) {
            $words = is_array($text) ? ($text[$language] ?? null) : null;
            if (!is_string($words) || trim($words) === '' || mb_strlen($words) > $max) {
                $errors[] = "{$path}.{$key}.{$language}: " . ($required ? 'required, ' : '') . "at most {$max} characters";
            }
        }

        return $errors;
    }

    /**
     * @param array<mixed> $value
     * @return list<string>
     */
    private static function iconErrors(array $value, string $path): array
    {
        if (isset($value['icon']) && (!is_string($value['icon']) || preg_match(self::ICON_PATTERN, $value['icon']) !== 1)) {
            return ["{$path}.icon: lowercase letters, digits and dashes"];
        }

        return [];
    }

    private static function isPermission(mixed $value): bool
    {
        return is_string($value) && preg_match(self::PERMISSION_PATTERN, $value) === 1;
    }

    private static function isRoute(mixed $value): bool
    {
        return is_string($value) && preg_match(self::ROUTE_PATTERN, $value) === 1;
    }
}
