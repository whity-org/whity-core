<?php

declare(strict_types=1);

namespace Whity\Api;

use Whity\Core\CustomerArea\CustomerScreenAccess;
use Whity\Core\RBAC\RoleGrantGuard;
use Whity\Core\Request;
use Whity\Core\Response;
use Whity\Core\Settings\SettingsRegistry;
use Whity\Core\Settings\SettingsService;
use Whity\Core\Tenant\TenantContext;
use Whity\Http\JsonBody;

/**
 * Settings › Customer area (design section 41 §12, CA.22, WHIT-1416): whether
 * the workspace's customers can sign in to their area, which core tabs show
 * and in what order, the welcome line and How to pay, in English and Arabic.
 *
 * Read with `settings:read`, written with `settings:write`. The keys are
 * own-screen keys, so the generic settings PATCH refuses them.
 *
 * Plugin screens (WHIT-1438, Q8): every enabled plugin's customer screens,
 * each with a switch (on unless listed in `hidden_screens`) and whether the
 * workspace's customers hold its permission. Giving them that permission is
 * its own call, `POST …/screens/{screen}/grant` with `{"confirm": true}`,
 * which needs `roles:write` and grants only what the admin holds.
 */
final class CustomerAreaSettingsApiHandler
{
    /** API field => setting key. */
    private const FIELDS = [
        'enabled' => SettingsRegistry::CUSTOMER_AREA_ENABLED,
        'hidden_tabs' => SettingsRegistry::CUSTOMER_AREA_HIDDEN_TABS,
        'tab_order' => SettingsRegistry::CUSTOMER_AREA_TAB_ORDER,
        'welcome_en' => SettingsRegistry::CUSTOMER_AREA_WELCOME_EN,
        'welcome_ar' => SettingsRegistry::CUSTOMER_AREA_WELCOME_AR,
        'how_to_pay_en' => SettingsRegistry::CUSTOMER_AREA_HOW_TO_PAY_EN,
        'how_to_pay_ar' => SettingsRegistry::CUSTOMER_AREA_HOW_TO_PAY_AR,
        'hidden_screens' => SettingsRegistry::CUSTOMER_AREA_HIDDEN_SCREENS,
    ];

    public function __construct(
        private readonly SettingsService $settings,
        private readonly \Closure $handleOf,
        /** @var (\Closure(): list<array<string, mixed>>)|null the enabled plugins' customer screens (SDK 1.174) */
        private readonly ?\Closure $pluginScreens = null,
        private readonly ?CustomerScreenAccess $access = null,
        private readonly ?RoleGrantGuard $grantGuard = null,
    ) {
    }

    /**
     * Give the workspace's customers a plugin screen's permission, after the
     * admin confirmed (Q8), and turn the screen on.
     *
     * @param array<string, string> $params
     */
    public function grant(Request $request, array $params = []): Response
    {
        $tenantId = TenantContext::getTenantId();
        $user = $request->user;
        $actor = is_object($user) ? ($user->profile_id ?? null) : null;
        if ($tenantId === null || $tenantId <= 0 || !is_int($actor) || $this->access === null) {
            return Response::error('Open a workspace first.', 404);
        }
        $screen = $this->findScreen((string) ($params['screen'] ?? ''));
        if ($screen === null) {
            return Response::error('No enabled plugin offers that screen.', 404);
        }
        if ((JsonBody::parsed($request)['confirm'] ?? null) !== true) {
            return Response::error('Confirm giving your customers this screen first.', 422, ['reason' => 'confirm_required']);
        }
        $permission = (string) ($screen['requiredPermission'] ?? '');
        if ($this->grantGuard !== null) {
            $missing = $this->grantGuard->notHeld($actor, $tenantId, [$permission]);
            if ($missing !== []) {
                return RoleGrantGuard::refusal($missing, 'You can only give customers what you have yourself.');
            }
        }
        $result = $this->access->grant($tenantId, $permission, $actor);
        if ($result === null) {
            return Response::error('This screen cannot be given to customers.', 409, ['reason' => 'not_customer_grade']);
        }
        $hidden = $this->hiddenScreens($tenantId);
        if (in_array($screen['id'], $hidden, true)) {
            $this->settings->setTenant(
                $tenantId,
                SettingsRegistry::CUSTOMER_AREA_HIDDEN_SCREENS,
                (string) json_encode(array_values(array_diff($hidden, [(string) $screen['id']])))
            );
        }

        return Response::json(['data' => $this->present($tenantId) + ['grant' => $result]]);
    }

    /** @return array<string, mixed>|null */
    private function findScreen(string $id): ?array
    {
        if ($this->pluginScreens === null || preg_match('/^[a-z][a-z0-9-]{0,63}$/', $id) !== 1) {
            return null;
        }
        foreach (($this->pluginScreens)() as $screen) {
            if (($screen['id'] ?? null) === $id) {
                return $screen;
            }
        }

        return null;
    }

    /** @return list<string> */
    private function hiddenScreens(int $tenantId): array
    {
        $v = json_decode($this->settings->effective($tenantId)[SettingsRegistry::CUSTOMER_AREA_HIDDEN_SCREENS] ?? '[]', true);

        return is_array($v) ? array_values(array_filter($v, 'is_string')) : [];
    }

    /**
     * Every enabled plugin's customer screens, for the settings page.
     *
     * @return list<array<string, mixed>>
     */
    private function screens(int $tenantId): array
    {
        if ($this->pluginScreens === null) {
            return [];
        }
        $hidden = $this->hiddenScreens($tenantId);
        $out = [];
        foreach (($this->pluginScreens)() as $screen) {
            $permission = (string) ($screen['requiredPermission'] ?? '');
            $roles = $this->access?->rolesFor($tenantId, $permission) ?? [];
            $out[] = [
                'id' => (string) $screen['id'],
                'label' => $screen['label'] ?? $screen['id'],
                'plugin' => (string) ($screen['plugin'] ?? ''),
                'placement' => (string) ($screen['placement'] ?? 'tab'),
                'permission' => $permission,
                'on' => !in_array($screen['id'], $hidden, true),
                'customers_have' => array_filter($roles, static fn (array $r): bool => !$r['has']) === [],
                'roles' => array_map(static fn (array $r): array => [
                    'id' => $r['id'],
                    'name' => $r['name'],
                    'signup' => $r['signup'],
                    'customers' => $r['customers'],
                    'has' => $r['has'],
                    'skipped' => $r['skipped'],
                ], $roles),
            ];
        }
        usort($out, static fn (array $a, array $b): int => [$a['plugin'], $a['id']] <=> [$b['plugin'], $b['id']]);

        return $out;
    }

    /** @param array<string, string> $params */
    public function show(Request $request, array $params = []): Response
    {
        $tenantId = TenantContext::getTenantId();
        if ($tenantId === null || $tenantId <= 0) {
            return Response::error('Open a workspace first.', 404);
        }

        return Response::json(['data' => $this->present($tenantId)]);
    }

    /** @param array<string, string> $params */
    public function update(Request $request, array $params = []): Response
    {
        $tenantId = TenantContext::getTenantId();
        if ($tenantId === null || $tenantId <= 0) {
            return Response::error('Open a workspace first.', 404);
        }
        $body = JsonBody::parsed($request);
        $writes = [];
        foreach (self::FIELDS as $field => $key) {
            if (!array_key_exists($field, $body)) {
                continue;
            }
            $value = $body[$field];
            $text = match (true) {
                $field === 'enabled' && is_bool($value) => $value ? 'true' : 'false',
                in_array($field, ['hidden_tabs', 'tab_order', 'hidden_screens'], true) && is_array($value) => (string) json_encode(array_values($value)),
                is_string($value) => trim($value),
                default => null,
            };
            $error = $text === null ? $field . ' has the wrong type.' : SettingsRegistry::validate($key, $text);
            if ($error !== null) {
                return Response::error('Check the customer area settings.', 422, ['field' => $field, 'details' => $error]);
            }
            $writes[$key] = (string) $text;
        }
        foreach ($writes as $key => $text) {
            $this->settings->setTenant($tenantId, $key, $text);
        }

        return Response::json(['data' => $this->present($tenantId)]);
    }

    /** @return array<string, mixed> */
    private function present(int $tenantId): array
    {
        $e = $this->settings->effective($tenantId);
        $list = static function (string $key) use ($e): array {
            $v = json_decode($e[$key] ?? '[]', true);

            return is_array($v) ? array_values(array_filter($v, 'is_string')) : [];
        };

        return [
            'enabled' => ($e[SettingsRegistry::CUSTOMER_AREA_ENABLED] ?? 'true') === 'true',
            'hidden_tabs' => $list(SettingsRegistry::CUSTOMER_AREA_HIDDEN_TABS),
            'tab_order' => $list(SettingsRegistry::CUSTOMER_AREA_TAB_ORDER),
            'welcome_en' => (string) ($e[SettingsRegistry::CUSTOMER_AREA_WELCOME_EN] ?? ''),
            'welcome_ar' => (string) ($e[SettingsRegistry::CUSTOMER_AREA_WELCOME_AR] ?? ''),
            'how_to_pay_en' => (string) ($e[SettingsRegistry::CUSTOMER_AREA_HOW_TO_PAY_EN] ?? ''),
            'how_to_pay_ar' => (string) ($e[SettingsRegistry::CUSTOMER_AREA_HOW_TO_PAY_AR] ?? ''),
            'handle' => ($this->handleOf)($tenantId),
            'tabs' => SettingsRegistry::CUSTOMER_AREA_TABS,
            'hidden_screens' => $this->hiddenScreens($tenantId),
            'screens' => $this->screens($tenantId),
        ];
    }
}
