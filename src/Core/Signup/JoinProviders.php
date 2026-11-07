<?php

declare(strict_types=1);

namespace Whity\Core\Signup;

use Whity\Core\Identity\IdentityProviderRepository;
use Whity\Core\Settings\SettingsRegistry;
use Whity\Core\Settings\SettingsService;

/**
 * The sign-in providers a visitor may join a workspace with (WHIT-917): the
 * operator's enabled, global-trust providers (tenant 0), and none at all when
 * the operator has switched single sign-on off. Display fields only.
 *
 * A workspace's own (tenant-trust) provider is not offered: it signs in that
 * workspace's existing members and never creates a membership.
 */
final class JoinProviders
{
    private const SYSTEM_TENANT_ID = 0;

    public function __construct(
        private readonly IdentityProviderRepository $idps,
        private readonly SettingsService $settings,
    ) {
    }

    /** @return list<array{provider_key: string, display_name: string}> */
    public function list(): array
    {
        try {
            $global = $this->settings->getGlobal();
            if (($global[SettingsRegistry::SSO_ENABLED] ?? 'true') !== 'true') {
                return [];
            }

            $out = [];
            foreach ($this->idps->listForTenant(self::SYSTEM_TENANT_ID) as $row) {
                if (($row['enabled'] ?? false) === true) {
                    $out[] = [
                        'provider_key' => (string) $row['provider_key'],
                        'display_name' => (string) $row['display_name'],
                    ];
                }
            }

            return $out;
        } catch (\Throwable $e) {
            error_log('[customer-signup] provider list failed: ' . $e->getMessage());

            return [];
        }
    }
}
