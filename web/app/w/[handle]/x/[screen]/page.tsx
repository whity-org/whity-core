'use client';

/**
 * A plugin's screen in the customer area (SDK 1.173, WHIT-1424; design
 * section 41 §9): `/@handle/x/{screen}`. Core answers it only when the
 * customer holds the screen's permission; the screen then calls its plugin
 * with the screen's customer token.
 */

import { useRouter } from 'next/navigation';
import { use } from 'react';
import { api } from '@/lib/api/client';
import { areaHref, useCustomerArea } from '@/lib/customer-area';
import { useTranslation } from '@whity/features/i18n';
import { LoadState, PageTitle, useAreaLoad, useLocalized } from '@/components/customer-area/area-parts';
import { ScreenBlocks, ScreenEmbed, type CustomerScreen } from '@/components/customer-area/plugin-screen';

export default function CustomerPluginScreen({ params }: { params: Promise<{ screen: string }> }) {
  const { screen: screenId } = use(params);
  const t = useTranslation('customer');
  const name = useLocalized();
  const router = useRouter();
  const { handle } = useCustomerArea();
  const [state, retry] = useAreaLoad<CustomerScreen>(
    () => api.GET('/api/v1/customer-area/{handle}/x/{screen}', { params: { path: { handle, screen: screenId } } }),
    [handle, screenId],
  );

  if (state.kind !== 'ready') {
    return <LoadState state={state} onRetry={retry} goneTitle={t('screen.gone', 'This page isn’t open to you')} />;
  }
  const screen = state.data;
  const label = typeof screen.label === 'string' ? screen.label : name(screen.label as Record<string, string> | null);

  return (
    <div className="space-y-5" data-testid="customer-plugin-screen">
      <PageTitle>{label}</PageTitle>
      {screen.screen === 'blocks' ? (
        <ScreenBlocks handle={handle} screenId={screen.id} apiPaths={screen.api_paths ?? []} blocks={screen.blocks ?? []} />
      ) : (
        <ScreenEmbed handle={handle} screen={screen} onClose={() => router.push(areaHref(handle, 'home'))} />
      )}
    </div>
  );
}
