'use client';

import { useEffect, useState } from 'react';
import { apiClient } from '@/lib/api-client';
import { Button } from '@whity/ui/button';
import { useTranslation } from '@whity/features/i18n';
import { Mark } from '@whity/ui/mark';
import { IconBrandGoogle, IconBrandWindows, IconLock } from '@tabler/icons-react';

/**
 * The public, display-safe shape of an enabled SSO provider, from
 * GET /api/v1/auth/sso/providers (tenant resolved by host; empty when the
 * instance SSO kill-switch is off). Only provider_key + display_name are
 * exposed — never client_id/secret/issuer.
 */
interface SsoProvider {
  provider_key: string;
  display_name: string;
}

/** Begin the hosted OIDC flow. A full-page navigation (NOT a client route):
 * the backend 302s to the provider and, on return, back to /dashboard or
 * /login?sso_error=… — so this must be a real document navigation. */
function startUrl(providerKey: string): string {
  return `/api/v1/auth/sso/${encodeURIComponent(providerKey)}/start`;
}

function ProviderIcon({ providerKey }: { providerKey: string }) {
  if (providerKey === 'google') return <IconBrandGoogle className="h-4 w-4" aria-hidden="true" />;
  // Whity ID, the Whity identity service, keyed whity_id (SSO provider keys are [a-z0-9_]+).
  if (providerKey === 'whity_id') return <Mark size={16} label="" />;
  if (providerKey === 'microsoft') return <IconBrandWindows className="h-4 w-4" aria-hidden="true" />;
  return <IconLock className="h-4 w-4" aria-hidden="true" />;
}

/**
 * Renders a "Sign in with <Provider>" button for each ENABLED identity provider
 * configured for the current tenant. Fetches the public providers list on mount
 * and renders nothing (no divider, no buttons) when there are none — so a
 * password-only instance is visually unchanged.
 */
export function SsoLoginButtons() {
  const t = useTranslation('auth');
  const [providers, setProviders] = useState<SsoProvider[]>([]);

  useEffect(() => {
    let cancelled = false;
    void (async () => {
      try {
        const res = await apiClient('/api/v1/auth/sso/providers', { skipRefresh: true });
        if (!res.ok) return;
        const body: unknown = await res.json();
        const data =
          body && typeof body === 'object' && Array.isArray((body as { data?: unknown }).data)
            ? ((body as { data: unknown[] }).data as SsoProvider[])
            : [];
        const valid = data.filter(
          (p): p is SsoProvider =>
            !!p && typeof p.provider_key === 'string' && typeof p.display_name === 'string'
        );
        if (!cancelled) setProviders(valid);
      } catch {
        // Fail closed: no buttons rendered if the listing is unavailable.
      }
    })();
    return () => {
      cancelled = true;
    };
  }, []);

  if (providers.length === 0) {
    return null;
  }

  return (
    <div className="space-y-3" data-testid="sso-login-buttons">
      {providers.map((provider) => (
        <Button
          key={provider.provider_key}
          asChild
          variant="outline"
          className="w-full gap-2"
          data-testid={`sso-start-${provider.provider_key}`}
        >
          <a href={startUrl(provider.provider_key)}>
            <ProviderIcon providerKey={provider.provider_key} />
            {t('sso.signInWith', 'Continue with {provider}', {
              provider: provider.display_name,
            })}
          </a>
        </Button>
      ))}
      <div className="flex items-center gap-3">
        <span className="h-px flex-1 bg-border" />
        <span className="text-sm text-muted-foreground">{t('sso.divider', 'or with email')}</span>
        <span className="h-px flex-1 bg-border" />
      </div>
    </div>
  );
}
