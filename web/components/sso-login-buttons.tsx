'use client';

import { useEffect, useState } from 'react';
import { api } from '@/lib/api/client';
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
function startUrl(providerKey: string, invite?: string): string {
  const base = `/api/v1/auth/sso/${encodeURIComponent(providerKey)}/start`;
  // WHIT-1168: an invitation accepted by signing in rides along to the callback.
  return invite ? `${base}?invite=${encodeURIComponent(invite)}` : base;
}

/** What the sign-in, sign-up and invitation pages learn from the providers list. */
export interface SsoSignInInfo {
  providers: SsoProvider[];
  /** False when the server signs people in only through its identity provider (WHIT-1168). */
  passwordSignIn: boolean;
}

export function ProviderIcon({ providerKey }: { providerKey: string }) {
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
export function SsoLoginButtons({
  onLoaded,
  invite,
}: {
  /** Told once the list is in, so the page can drop the password form when it is off. */
  onLoaded?: (info: SsoSignInInfo) => void;
  /** An invitation token to accept by signing in. */
  invite?: string;
} = {}) {
  const t = useTranslation('auth');
  const [providers, setProviders] = useState<SsoProvider[]>([]);
  const [passwordSignIn, setPasswordSignIn] = useState(true);

  useEffect(() => {
    let cancelled = false;
    void (async () => {
      try {
        const { data: listing, response: res } = await api.GET('/api/v1/auth/sso/providers');
        if (!res.ok) return;
        const body: unknown = listing;
        const data =
          body && typeof body === 'object' && Array.isArray((body as { data?: unknown }).data)
            ? ((body as { data: unknown[] }).data as SsoProvider[])
            : [];
        const valid = data.filter(
          (p): p is SsoProvider =>
            !!p && typeof p.provider_key === 'string' && typeof p.display_name === 'string'
        );
        // Anything but an explicit false keeps the password form: the flag
        // only ever takes a way in away.
        const passwords =
          !(body && typeof body === 'object' && (body as { password_sign_in?: unknown }).password_sign_in === false);
        if (!cancelled) {
          setProviders(valid);
          setPasswordSignIn(passwords);
          onLoaded?.({ providers: valid, passwordSignIn: passwords });
        }
      } catch {
        // Fail closed: no buttons rendered if the listing is unavailable.
      }
    })();
    return () => {
      cancelled = true;
    };
    // Read once on mount; onLoaded is a page callback, not a reason to refetch.
    // eslint-disable-next-line react-hooks/exhaustive-deps
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
          <a href={startUrl(provider.provider_key, invite)}>
            <ProviderIcon providerKey={provider.provider_key} />
            {t('sso.signInWith', 'Continue with {provider}', {
              provider: provider.display_name,
            })}
          </a>
        </Button>
      ))}
      {passwordSignIn ? (
        <div className="flex items-center gap-3">
          <span className="h-px flex-1 bg-border" />
          <span className="text-sm text-muted-foreground">{t('sso.divider', 'or with email')}</span>
          <span className="h-px flex-1 bg-border" />
        </div>
      ) : null}
    </div>
  );
}
