<?php

declare(strict_types=1);

namespace Whity\Api;

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
    ];

    public function __construct(
        private readonly SettingsService $settings,
        private readonly \Closure $handleOf,
    ) {
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
                in_array($field, ['hidden_tabs', 'tab_order'], true) && is_array($value) => (string) json_encode(array_values($value)),
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
        ];
    }
}
