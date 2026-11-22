'use client';

import { useEffect, useState } from 'react';
import { useSearchParams } from 'next/navigation';
import { api } from '@/lib/api/client';
import { Button } from '@whity/ui/button';
import { Card, CardContent, CardDescription, CardHeader, CardTitle } from '@whity/ui/card';
import { Alert, AlertDescription } from '@whity/ui/alert';
import { useTranslation } from '@whity/features/i18n';
import { ProviderIcon } from '@/components/sso-login-buttons';

interface Provider {
  provider_key: string;
  display_name: string;
}

interface LinkedIdentity {
  id: number;
  provider_key: string;
  email: string | null;
}

/**
 * My settings › Password and two-step: link the server's sign-in provider, such
 * as Whity ID, to this account (WHIT-1350).
 *
 * A first sign-in with the provider links only when its verified address
 * matches this account's. Linking here works whatever the address: the person
 * is already signed in, and the provider proves who they are there. It is how
 * a platform operator who came in with a one-time link keeps a way in once
 * password sign-in is off.
 *
 * Starting the link is a full navigation: the server sends the person to the
 * provider and back to this page with `?identity_link=<outcome>`.
 */
export function IdentityLinkCard() {
  const t = useTranslation('auth');
  const searchParams = useSearchParams();
  const outcome = searchParams.get('identity_link');
  const fromOperatorLink = searchParams.get('link') === 'whity_id';
  const [providers, setProviders] = useState<Provider[]>([]);
  const [linked, setLinked] = useState<LinkedIdentity[]>([]);
  const [loaded, setLoaded] = useState(false);
  const [removeError, setRemoveError] = useState<string | null>(null);
  const [removing, setRemoving] = useState<number | null>(null);

  const remove = async (id: number) => {
    setRemoveError(null);
    setRemoving(id);
    try {
      const { response, error } = await api.DELETE('/api/v1/me/identities/{id}', { params: { path: { id } } });
      if (response.status === 204) {
        setLinked((rows) => rows.filter((row) => row.id !== id));
        return;
      }
      const message = (error as { error?: unknown } | undefined)?.error;
      setRemoveError(
        typeof message === 'string' && message !== ''
          ? message
          : t('identityLink.removeFailed', 'It wasn’t removed. Try again.'),
      );
    } catch {
      setRemoveError(t('identityLink.removeFailed', 'It wasn’t removed. Try again.'));
    } finally {
      setRemoving(null);
    }
  };

  useEffect(() => {
    let cancelled = false;
    void (async () => {
      try {
        const [list, mine] = await Promise.all([
          api.GET('/api/v1/auth/sso/providers'),
          api.GET('/api/v1/me/identities'),
        ]);
        if (cancelled) return;
        const providerBody: unknown = list.data;
        const providerRows =
          providerBody && typeof providerBody === 'object' && Array.isArray((providerBody as { data?: unknown }).data)
            ? ((providerBody as { data: Provider[] }).data)
            : [];
        const mineBody: unknown = mine.data;
        const linkedRows =
          mineBody && typeof mineBody === 'object' && Array.isArray((mineBody as { data?: unknown }).data)
            ? ((mineBody as { data: LinkedIdentity[] }).data)
            : [];
        setProviders(providerRows);
        setLinked(linkedRows);
      } catch {
        // Nothing to show: the card stays hidden.
      } finally {
        if (!cancelled) setLoaded(true);
      }
    })();
    return () => {
      cancelled = true;
    };
  }, []);

  if (!loaded || (providers.length === 0 && linked.length === 0)) return null;

  const outcomeText: Record<string, string> = {
    linked: t('identityLink.outcome.linked', 'Linked. From now on, sign in with it.'),
    taken: t(
      'identityLink.outcome.taken',
      'That account is already linked to someone else here. Sign in at the provider with a different account and try again.',
    ),
    already_linked: t(
      'identityLink.outcome.alreadyLinked',
      'You already have an account at this provider linked. Remove it first to link a different one.',
    ),
    sign_in_first: t('identityLink.outcome.signInFirst', 'Your session ended. Sign in again, then link.'),
    provider_unavailable: t(
      'identityLink.outcome.providerUnavailable',
      'The provider can’t be reached right now. Try again in a few minutes.',
    ),
    failed: t('identityLink.outcome.failed', 'Nothing was linked. Try again.'),
  };
  const linkedKeys = new Set(linked.map((row) => row.provider_key));
  const unlinkedProviders = providers.filter((p) => !linkedKeys.has(p.provider_key));
  const nameOf = (key: string) => providers.find((p) => p.provider_key === key)?.display_name ?? key;

  return (
    <Card data-testid="identity-link-card">
      <CardHeader>
        <CardTitle>{t('identityLink.title', 'Sign-in accounts')}</CardTitle>
        <CardDescription>
          {t(
            'identityLink.description',
            'Link the account you sign in with, such as Whity ID. It works even if its email is different from this account’s.',
          )}
        </CardDescription>
      </CardHeader>
      <CardContent className="space-y-4">
        {outcome && outcomeText[outcome] && (
          <Alert variant={outcome === 'linked' ? 'default' : 'destructive'} data-testid="identity-link-outcome">
            <AlertDescription>{outcomeText[outcome]}</AlertDescription>
          </Alert>
        )}
        {fromOperatorLink && !outcome && unlinkedProviders.length > 0 && (
          <Alert data-testid="identity-link-operator">
            <AlertDescription>
              {t(
                'identityLink.operatorHint',
                'You signed in with a one-time link. Link your Whity ID now: the link won’t work again.',
              )}
            </AlertDescription>
          </Alert>
        )}

        {removeError && (
          <Alert variant="destructive" data-testid="identity-link-remove-error">
            <AlertDescription>{removeError}</AlertDescription>
          </Alert>
        )}

        {linked.length > 0 && (
          <ul className="space-y-2">
            {linked.map((row) => (
              <li key={row.id} className="flex items-center gap-3 text-sm">
                <ProviderIcon providerKey={row.provider_key} />
                <span className="font-medium">{nameOf(row.provider_key)}</span>
                {row.email && <span className="text-muted-foreground">{row.email}</span>}
                <Button
                  variant="outline"
                  size="sm"
                  className="ms-auto"
                  disabled={removing === row.id}
                  onClick={() => void remove(row.id)}
                >
                  {t('identityLink.remove', 'Remove')}
                </Button>
              </li>
            ))}
          </ul>
        )}

        {unlinkedProviders.map((p, index) => (
          <Button
            key={p.provider_key}
            asChild
            variant={index === 0 ? 'default' : 'outline'}
            className="w-full sm:w-auto"
          >
            <a href={`/api/v1/auth/sso/${encodeURIComponent(p.provider_key)}/start?link=1`}>
              <ProviderIcon providerKey={p.provider_key} />
              {t('identityLink.link', 'Link {provider}', { provider: p.display_name })}
            </a>
          </Button>
        ))}
      </CardContent>
    </Card>
  );
}
