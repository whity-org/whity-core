<?php

declare(strict_types=1);

namespace Whity\Core\Entitlement;

/**
 * Which brand features a workspace's plan includes (WHIT-932).
 *
 * One place to ask, so the features that do not exist yet have their check
 * waiting for them rather than each inventing its own:
 *
 *  - own web address: `tenants.branding_host`. Setting one without it warns
 *    (BrandingApiHandler) and shows on Billing; it is never taken away.
 *  - "Built on Whity" removed: read by BrandingService for public pages.
 *  - own sign-in: `sso.tenant_idp`, which existed before this.
 *  - own email sender and own app in the stores: no per-workspace feature
 *    exists yet. Whatever builds one asks here first.
 */
final class BrandFeatures
{
    public function __construct(private readonly EntitlementService $entitlements)
    {
    }

    public function ownWebAddress(int $tenantId): bool
    {
        return $this->entitlements->isGranted($tenantId, EntitlementRegistry::BRAND_CUSTOM_DOMAIN);
    }

    public function removesBuiltOnWhity(int $tenantId): bool
    {
        return $this->entitlements->isGranted($tenantId, EntitlementRegistry::BRAND_REMOVE_BADGE);
    }

    public function ownSignIn(int $tenantId): bool
    {
        return $this->entitlements->isGranted($tenantId, EntitlementRegistry::SSO_TENANT_IDP);
    }

    public function ownEmailSender(int $tenantId): bool
    {
        return $this->entitlements->isGranted($tenantId, EntitlementRegistry::BRAND_OWN_EMAIL_SENDER);
    }

    public function ownApp(int $tenantId): bool
    {
        return $this->entitlements->isGranted($tenantId, EntitlementRegistry::BRAND_OWN_APP);
    }
}
